<?php

namespace App\Models\Sakemaru;

use App\Enums\BillingType;
use App\Enums\EAllocationType;
use App\Models\Sakemaru\ClosingBill;
use App\Models\Sakemaru\CustomModel;
use App\Models\Sakemaru\Earning;
use App\Models\Sakemaru\LedgerClassification;
use App\Models\Sakemaru\Partner;
use App\Models\Sakemaru\Purchase;
use DB;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class Bill extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];


    public function earnings(): HasMany
    {
        return $this->hasMany(Earning::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function ledger_classification(): BelongsTo
    {
        return $this->belongsTo(LedgerClassification::class);
    }

    public function closing_bill(): BelongsTo
    {
        return $this->belongsTo(ClosingBill::class);
    }

    public static function currentMonthlyBills(bool $as_query = false): Collection|Builder
    {
        $query = static::whereNull('closing_monthly_id');
        if ($as_query) {
            return $query;
        }
        return $query->get();
    }

    public static function tentativeBill($partner_id, bool $or_create = false, ?int $client_id = null, ?int $creator_id = null): ?Bill
    {
        if ($or_create) {
            $partner = Partner::find($partner_id);
            $creator_id = $creator_id ?? auth()->user()->id ?? 0;
            return static::firstOrCreate([
                'partner_id' => $partner_id,
                'is_tentative' => true,
            ], [
                'client_id' => $client_id ?? $partner->client_id,
                'partner_id' => $partner_id,
                'is_from_supplier' => $partner->is_supplier,
                'creator_id' => $creator_id,
                'last_updater_id' => $creator_id,
                'ledger_classification_id' => 0,
                'number' => 0,
                'billing_type' => BillingType::UNDEFINED,
                'is_tentative' => true,
            ]);
        }
        return static::where('partner_id', $partner_id)
            ->where('is_tentative', true)
            ->first();
    }

    public static function allocateTentative($client_id, $partner_id, $creator_id, $price, EAllocationType $allocation_type): self
    {
        $bill = self::tentativeBill($partner_id, true, $client_id, $creator_id);
        $col = $allocation_type->allocationCol();
        $bill->$col += $price;
        $bill->save();
        return $bill;
    }

    protected function totalAllocation(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->container_pickup_amount + $this->allocation_amount + $this->discount_amount,
        );
    }

    protected function expectedAmount(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->amount - $this->total_allocation,
        );
    }


    // ... 既存のプロパティやリレーション、メソッド

    /**
     * 指定されたclosing_bill_idに紐づく全てのBillレコードの
     * effective_closing_dateをSQLで一括更新します。
     * 締め処理など、関連レコードが一度に作成された後のリアルタイム更新に最適です。
     *
     * @param int $closingBillId 更新対象の締め請求ID
     * @return void
     */
    public static function bulkUpdateEffectiveClosingDateByClosingId(int $closingBillId): void
    {
        // 無効なIDの場合は何もしない
        if ($closingBillId <= 0) {
            return;
        }

        // 念のため、BUYER用とSUPPLIER用の更新をトランザクション内で実行

        // --- BUYER用の更新 (is_from_supplier = 0) ---
        // 更新対象のBUYER請求が存在するか確認
        $hasBuyerBills = DB::table('bills')
            ->where('closing_bill_id', $closingBillId)
            ->where('is_from_supplier', 0)
            ->exists();

        if ($hasBuyerBills) {
            DB::statement("
                    UPDATE bills b
                    LEFT JOIN closing_bills cb ON b.closing_bill_id = cb.id
                    LEFT JOIN (
                        SELECT e.bill_id, latest_earnings.account_date
                        FROM (
                            SELECT bill_id, MAX(id) AS max_id
                            FROM earnings
                            GROUP BY bill_id
                        ) e
                        JOIN earnings AS latest_earnings ON e.max_id = latest_earnings.id
                    ) AS le ON b.id = le.bill_id
                    SET
                        b.effective_closing_date = COALESCE(cb.closing_date, le.account_date)
                    WHERE
                        b.is_from_supplier = 0
                        AND b.closing_bill_id = ?
                ", [$closingBillId]);
        }

        // --- SUPPLIER用の更新 (is_from_supplier = 1) ---
        // 更新対象のSUPPLIER請求が存在するか確認
        $hasSupplierBills = DB::table('bills')
            ->where('closing_bill_id', $closingBillId)
            ->where('is_from_supplier', 1)
            ->exists();

        if ($hasSupplierBills) {
            DB::statement("
                    UPDATE bills b
                    LEFT JOIN closing_bills cb ON b.closing_bill_id = cb.id
                    LEFT JOIN (
                        SELECT p.bill_id, latest_purchases.account_date
                        FROM (
                            SELECT bill_id, MAX(id) AS max_id
                            FROM purchases
                            GROUP BY bill_id
                        ) p
                        JOIN purchases AS latest_purchases ON p.max_id = latest_purchases.id
                    ) AS lp ON b.id = lp.bill_id
                    SET
                        b.effective_closing_date = COALESCE(cb.closing_date, lp.account_date)
                    WHERE
                        b.is_from_supplier = 1
                        AND b.closing_bill_id = ?
                ", [$closingBillId]);
        }
        Log::info("effective_closing_date が closing_bill_id: {$closingBillId} に対して一括更新されました。");
    }

    /**
     * ソート用の実効締め日を計算して更新する
     */
    public function updateEffectiveClosingDate()
    {
        $closingDate = optional($this->closing_bill)->closing_date;

        $latestAccountDate = null;

        // is_from_supplier フラグによって参照するテーブルを切り替える
        if ($this->is_from_supplier) {
            // サプライヤーからの請求の場合、purchasesテーブルの最新日を取得
            $latestAccountDate = $this->purchases()->latest('id')->value('account_date');
        } else {
            // 顧客への請求の場合、earningsテーブルの最新日を取得
            $latestAccountDate = $this->earnings()->latest('id')->value('account_date');
        }

        // 締め日、または最新の入出金日を実効日として設定
        $effectiveDate = $closingDate ?? $latestAccountDate;

        // 値が変更された場合のみDB更新を実行
        if ($this->effective_closing_date != $effectiveDate) {
            $this->effective_closing_date = $effectiveDate;
            $this->save();
        }
    }
}
