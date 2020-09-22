<?php

namespace App\BusinessCase\User;

use App\BusinessCase\Exceptions\InvalidPaymentOptionException;
use App\AppBundle\Form\Payment\Payout\Type\BankTransferType;
use App\AppBundle\Form\Payment\Payout\Type\PaypalType;
use App\Entity\Security\User;
use App\Repository\Payment\PaymentRepositoryInterface;
use App\Repository\User\UserRepositoryInterface;
use App\Service\Email\EmailServiceException;
use App\Service\Email\EmailServiceInterface;
use App\ValueObject\Api\Request\ChangeCompanyRequest;
use App\ValueObject\Email\ChangeCompanyMessage;
use App\ValueObject\Invoicing\Invoicing;
use App\ValueObject\Payment\Payout;
use App\ValueObject\Payment\Payout\Option;
use App\ValueObject\Payment\Payout\Type;
use App\ValueObject\Payment\Payout\Type\BankTransfer;
use App\ValueObject\Payment\Payout\Type\Paypal;
use App\ValueObject\Settings\Settings;
use Invoicing\Sdk\Domain\Model\Client\BankAccount;
use Invoicing\Sdk\Domain\Model\Client\PaymentAccount;
use Invoicing\Sdk\Domain\Request\IdRequest;
use Invoicing\Sdk\Domain\Sdk\ClientSdk as InvoicingSdk;
use Invoicing\Sdk\Domain\Value\AccountTypes;

class UserSettingsBusinessCase implements UserSettingsBusinessCaseInterface
{
    /** @var UserRepositoryInterface $userRepository */
    protected $userRepository;

    /** @var PaymentRepositoryInterface $paymentRepository */
    protected $paymentRepository;

    /** @var InvoicingSdk $invoicingSdk */
    protected $invoicingSdk;

    /** @var EmailServiceInterface $emailService */
    protected $emailService;

    /**
     * @param UserRepositoryInterface    $userRepository
     * @param PaymentRepositoryInterface $paymentRepository
     * @param InvoicingSdk               $invoicingSdk
     * @param EmailServiceInterface      $emailService
     */
    public function __construct(
        UserRepositoryInterface $userRepository,
        PaymentRepositoryInterface $paymentRepository,
        InvoicingSdk $invoicingSdk,
        EmailServiceInterface $emailService
    ) {
        $this->userRepository = $userRepository;
        $this->paymentRepository = $paymentRepository;
        $this->invoicingSdk = $invoicingSdk;
        $this->emailService = $emailService;
    }

    /**
     * {@inheritdoc}
     */
    public function getSettings(User $user)
    {
        $settings = new Settings();
        $settings->setUser($this->normalizeUser($user));

        $bankTransfer = new BankTransfer();
        $paypal = new Paypal();

        $payoutType = new Type();
        $payoutOption = new Option();
        $payout = new Payout();

        /** @var Invoicing $invoicing */
        $invoicing = $this->paymentRepository->getUserPaymentData($user->getId());

        if ($invoicing) {
            $bankTransfer->setAccountHolder($invoicing->getBankTransfer()->getAccountHolder());
            $bankTransfer->setBankName($invoicing->getBankTransfer()->getBankName());
            $bankTransfer->setIban($invoicing->getBankTransfer()->getIban());
            $bankTransfer->setBic($invoicing->getBankTransfer()->getBic());

            $paypal->setEmail($invoicing->getPaypal()->getEmail());

            $payoutOption->setDefault($invoicing->getDefaultType());
        } else {
            $payoutOption->setDefault(BankTransferType::getAliasName());
        }

        $payoutType->setBankTransfer($bankTransfer);
        $payoutType->setPaypal($paypal);

        $payout->setType($payoutType);
        $payout->setOption($payoutOption);

        $settings->setPayout($payout);

        return $settings;
    }

    /**
     * {@inheritdoc}
     */
    public function saveUser(User $user)
    {
        $this->userRepository->updateUser($user);
    }

    /**
     * {@inheritdoc}
     */
    public function getUser($userId)
    {
        return $this->userRepository->getUserWithRoles($userId);
    }

    /**
     * {@inheritdoc}
     */
    public function saveBankTransfer($userId, BankTransfer $bankTransfer)
    {
        $this->savePaymentAccount($userId, $bankTransfer);

        return $this->getInvoicingPaymentAccount($userId);
    }

    /**
     * {@inheritdoc}
     */
    public function savePaypal($userId, Paypal $paypal)
    {
        $this->savePaymentAccount($userId, $paypal);
    }

    /**
     * {@inheritdoc}
     */
    public function saveDefaultPaymentType($userId, Option $option)
    {
        /** @var PaymentAccount $paymentAccountInvoicing */
        $paymentAccountInvoicing = $this->getInvoicingPaymentAccount($userId);

        switch ($option->getDefault()) {
            case BankTransferType::getAliasName():
                $paymentTypeInvoicing = AccountTypes::BANK_ACCOUNT;
                break;
            case PaypalType::getAliasName():
                $paymentTypeInvoicing = AccountTypes::PAYPAL_ACCOUNT;
                break;
            default:
                throw new InvalidPaymentOptionException();
        }

        $this->updatePaymentsInInvoicingAndApp(
            $userId,
            $paymentAccountInvoicing,
            $paymentTypeInvoicing,
            $option->getDefault()
        );
    }

    /**
     * {@inheritdoc}
     */
    public function changeCompany(
        User $user,
        ChangeCompanyRequest $changeCompanyRequest,
        $environment,
        $emailTo
    ) {
        $message = new ChangeCompanyMessage(
            $user->getId(),
            $user->getEmail(),
            $changeCompanyRequest->getCompany(),
            $environment
        );

        $failedRecipients = $this->emailService->send(
            $message->getSubject(),
            $emailTo,
            $message->getMessage()
        );

        if (!empty($failedRecipients)) {
            throw new EmailServiceException('Email not sent');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function hasUserSetValidPaymentMethod(User $user)
    {
        $userSettings = $this->getSettings($user);
        $defaultOption = $userSettings->getPayout()->getOption()->getDefault();
        $defaultPayout = (array) $userSettings->getPayout()->getType()->{'get'.$defaultOption}();
        if (array_filter($defaultPayout, function ($field) {
            return empty($field);
        })) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * @param User $user
     *
     * @return User $user
     */
    protected function normalizeUser($user)
    {
        if (is_null($user->getFirstName())) {
            $user->setFirstName('');
        }
        if (is_null($user->getLastName())) {
            $user->setLastName('');
        }

        return $user;
    }

    /**
     * Saves payment account information in invoicing using the SDK and also in our DB.
     *
     * @param $userId
     * @param $payment
     *
     * @throws InvalidPaymentOptionException
     */
    private function savePaymentAccount($userId, $payment)
    {
        /** @var PaymentAccount $paymentAccountInvoicing */
        $paymentAccountInvoicing = $this->getInvoicingPaymentAccount($userId);

        switch ($payment) {
            case $payment instanceof BankTransfer:
                $bankAccountData = [
                    'iban' => $payment->getIban(),
                    'bic' => $payment->getBic(),
                    'bankName' => $payment->getBankName(),
                    'holderName' => $payment->getAccountHolder(),
                ];
                $bankAccount = new BankAccount($bankAccountData);

                $paymentAccountInvoicing->setBankAccount($bankAccount);
                $paymentTypeInvoicing = AccountTypes::BANK_ACCOUNT;
                $paymentTypeApp = BankTransferType::getAliasName();
                break;
            case $payment instanceof Paypal:
                $paymentAccountInvoicing->setPaypalAccount($payment->getEmail());
                $paymentTypeInvoicing = AccountTypes::PAYPAL_ACCOUNT;
                $paymentTypeApp = PaypalType::getAliasName();
                break;
            default:
                throw new InvalidPaymentOptionException();
        }

        $this->updatePaymentsInInvoicingAndApp(
            $userId,
            $paymentAccountInvoicing,
            $paymentTypeInvoicing,
            $paymentTypeApp
        );
    }

    /**
     * @param $userId
     *
     * @return PaymentAccount
     */
    private function getInvoicingPaymentAccount($userId)
    {
        return $this->invoicingSdk->getOrCreatePaymentAccount(new IdRequest($userId));
    }

    /**
     * @param int            $userId
     * @param PaymentAccount $paymentAccountInvoicing
     * @param string         $paymentTypeInvoicing
     * @param string         $paymentTypeApp
     */
    private function updatePaymentsInInvoicingAndApp(
        $userId,
        $paymentAccountInvoicing,
        $paymentTypeInvoicing,
        $paymentTypeApp
    ) {
        $paymentAccountInvoicing->setDefaultAccount($paymentTypeInvoicing);
        $this->invoicingSdk->persistPaymentAccount(
            new IdRequest($userId),
            $paymentAccountInvoicing
        );
        $this->invoicingSdk->done();

        $paymentAccountInvoicing = $this->getInvoicingPaymentAccount($userId);

        $this->paymentRepository->storeUserPaymentType(
            $userId,
            json_encode($paymentAccountInvoicing),
            $paymentTypeApp
        );
    }
}
