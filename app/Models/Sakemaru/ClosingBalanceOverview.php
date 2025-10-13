<?php

namespace App\Models\Sakemaru;
use App\Enums\EClosingType;
use App\Enums\Partners\EFraction;
use App\Enums\TaxType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ClosingBalanceOverview extends CustomModel
{
    protected $guarded = [];
    protected $casts = [
        'previous_balance_amount' => 'int',
        'balance_amount' => 'int',
    ];

    public function closing(): MorphTo
    {
        return $this->morphTo();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesman_id', 'id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function ledger_classification(): BelongsTo
    {
        return $this->belongsTo(LedgerClassification::class);
    }

    public function rebate_type(): BelongsTo
    {
        return $this->belongsTo(RebateType::class);
    }

    public function balance_price(): HasOne
    {
        return $this->hasOne(ClosingBalancePrice::class)
            ->where('is_returned', false);
    }

    public function return_balance_price(): HasOne
    {
        return $this->hasOne(ClosingBalancePrice::class)
            ->where('is_returned', true);
    }

    public function bill(): HasOne
    {
        return $this->hasOne(Bill::class);
    }

    public function rebate_bill(): HasOne
    {
        return $this->hasOne(RebateBill::class);
    }

    public function getBill() : Bill|RebateBill|null {
        if ($this->closing_type == ClosingBill::class) {
            return $this->bill;
        }
        else if ($this->closing_type == ClosingRebate::class) {
            return $this->rebate_bill;
        }
        return null;
    }

    public static function closingTable(string $closing_type) : string
    {
        return match ($closing_type) {
            ClosingBill::class => 'closing_bills',
            ClosingRebate::class => 'closing_rebates',
            ClosingDaily::class => 'closing_dailies',
            ClosingMonthly::class => 'closing_monthlies',
        };
    }

    public static function lastClosingBalance(
        string $closing_type,
        int $partner_id,
        ?int $branch_id = null,
        int $ledger_classification_id = 0,
        ?int $rebate_type_id = null,
        ?string $rebate_condition_type = null,
    ) : self|null
    {
        $closing_table = self::closingTable($closing_type);
        return self::query()
            ->leftJoin($closing_table, 'closing_balance_overviews.closing_id', '=', "{$closing_table}.id")
            ->where('closing_balance_overviews.closing_type', $closing_type)
//            ->where('branch_id', $branch_id)
            ->where('closing_balance_overviews.partner_id', $partner_id)
            ->where('closing_balance_overviews.ledger_classification_id', $ledger_classification_id)
            ->where('closing_balance_overviews.rebate_type_id', $rebate_type_id)
            ->where('closing_balance_overviews.rebate_condition_type', $rebate_condition_type)
            ->orderByDesc("{$closing_table}.closing_date") // 最後の締日取得
            ->orderByDesc('closing_balance_overviews.id')
            ->first();
    }

    public function previousClosingBalance() : self|null
    {
        //todo closing_dateをclosing_balance_overviewsに保存しておく
        $closing_table = self::closingTable($this->closing_type);
        return self::query()
            ->leftJoin($closing_table, 'closing_balance_overviews.closing_id', '=', "{$closing_table}.id")
            ->where('closing_balance_overviews.client_id', $this->client_id)
            ->where('closing_balance_overviews.closing_type', $this->closing_type)
//            ->where('branch_id', $this->branch_id)
            ->where('closing_balance_overviews.partner_id', $this->partner_id)
            ->where('closing_balance_overviews.ledger_classification_id', $this->ledger_classification_id)
            ->where('closing_balance_overviews.rebate_type_id', $this->rebate_type_id)
            ->where("{$closing_table}.closing_date", '<=', $this->closing->closing_date) // 選択した締日以前を取得
            ->where('closing_balance_overviews.id', '!=', $this->id)
            ->when($closing_table == "closing_bills", function ($query) {
                $query->where('closing_bills.is_tentative', false);
            })
            ->orderByDesc("{$closing_table}.closing_date")
            ->orderByDesc('closing_balance_overviews.id')
            ->first();
    }

    public function taxType() : TaxType
    {
        return TaxType::from($this->tax_type);
    }

    public function taxFraction() : EFraction
    {
        return EFraction::from($this->tax_fraction);
    }
}
