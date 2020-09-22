<?php

namespace App\Models;

use App\Services\FilterService;
use App\Services\Formatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Class AccountType
 * @package App
 */
class AccountType extends Model
{
    const UAH = 1;
    const USD = 2;
    const EUR = 3;
    const DEPOSIT_UAH = 4;

    protected $appends = ['accountsSum', 'invoicedSum', 'receivedSum', 'planningSum', 'expensesPlanSum', 'expensesRealSum'];

    protected $hidden = ['accountsSum', 'invoicedSum', 'receivedSum', 'planningSum', 'expensesSum'];

    /*
     * ***********************************
     * Accessors
     * ***********************************
     */

    /**
     * @return int|string
     */
    public function getAccountsSumAttribute()
    {
        return $this->getRelatedSum('accountsSum');
    }

    /**
     * @return int|string
     */
    public function getInvoicedSumAttribute()
    {
        return $this->getRelatedSum('invoicedSum');
    }

    /**
     * @return int|string
     */
    public function getReceivedSumAttribute()
    {
        return $this->getRelatedSum('receivedSum');
    }

    /**
     * @return int|string
     */
    public function getPlanningSumAttribute()
    {
        return $this->getRelatedSum('planningSum');
    }

    /**
     * @return int|string
     */
    public function getExpensesPlanSumAttribute()
    {
        return $this->getRelatedSum('expensesSum', 'plan_sum');
    }

    /**
     * @return int|string
     */
    public function getExpensesRealSumAttribute()
    {
        return $this->getRelatedSum('expensesSum', 'real_sum');
    }

    /*
     * ***********************************
     * Relations
     * ***********************************
     */

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function accountsSum()
    {
        return $this->hasOne(Account::class)
            ->selectRaw('sum(balance) as sum, account_type_id')
            ->groupBy('account_type_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function invoicedSum()
    {
        $filterService = app()->make(FilterService::class);
        $dates = [$filterService->getStartOfMonthDate(), $filterService->getEndOfMonthDate()];
        $invoiceSums = DB::table('invoice_items')
            ->select('invoice_id', DB::raw('sum(sum) as total'))
            ->whereBetween('invoice_items.created_at', $dates)
            ->whereNull('invoice_items.deleted_at')
            ->groupBy('invoice_id');

        return $this->hasOne(Account::class)
            ->selectRaw('sum(invoice_sums.total - invoices.discount * invoice_sums.total / (invoices.total + invoices.discount)) as sum, account_type_id')
            ->join('invoices', 'invoices.account_id', '=', 'accounts.id')
            ->leftJoinSub($invoiceSums, 'invoice_sums', function($join) {
                $join->on('invoice_sums.invoice_id', '=', 'invoices.id');
            })
            ->whereNull('invoices.deleted_at')
            ->groupBy('account_type_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function receivedSum()
    {
        return $this->hasOne(Account::class)
            ->selectRaw('sum(payments.received_sum) as sum, account_type_id')
            ->join('invoices', 'invoices.account_id', '=', 'accounts.id')
            ->join('payments', 'payments.invoice_id', '=', 'invoices.id')
            ->whereNull('invoices.deleted_at')
            ->whereNull('payments.deleted_at')
            ->groupBy('account_type_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function planningSum()
    {
        return $this->hasOne(Account::class)
            ->selectRaw('sum(incomes.plan_sum) as sum, account_type_id')
            ->join('incomes', 'incomes.account_id', '=', 'accounts.id')
            ->whereNull('incomes.deleted_at')
            ->groupBy('account_type_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function expensesSum()
    {
        return $this->hasOne(Account::class)
            ->selectRaw('sum(expenses.plan_sum) as plan_sum, sum(expenses.real_sum) as real_sum, account_type_id')
            ->join('expenses', 'expenses.account_id', '=', 'accounts.id')
            ->whereNull('expenses.deleted_at')
            ->groupBy('account_type_id');
    }

    /*
     * ***********************************
     * Other
     * ***********************************
     */

    /**
     * @param string $relation
     * @param string $field
     * @return int|string
     */
    private function getRelatedSum(string $relation, string $field = 'sum')
    {
        if (!array_key_exists($relation, $this->relations)) {
            $this->load($relation);
        }

        $related = $this->getRelation($relation);

        return $related ? Formatter::currency($related->$field, $this->symbol) : 0;
    }
}
