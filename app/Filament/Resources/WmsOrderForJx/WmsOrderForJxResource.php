<?php

namespace App\Filament\Resources\WmsOrderForJx;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\OrderChannel;
use App\Enums\EMenu;
use App\Filament\Resources\WmsOrderConfirmed\Tables\WmsOrderConfirmedTable;
use App\Filament\Resources\WmsOrderForJx\Pages\ListWmsOrderForJx;
use App\Filament\Support\AdminResource;
use App\Models\WmsOrderCandidate;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * JX発注データ作成
 *
 * 発注確定済みのうち JX 送信設定された発注先のデータを、発注代表グループ別タブで
 * 絞り込んで表示する。発注確定済み（WmsOrderConfirmed）と同じモデル・テーブル定義を
 * 流用し、標準フィルタ（JX未生成/FAX未生成/確定日〜当日）を初期値に設定する。
 */
class WmsOrderForJxResource extends AdminResource
{
    protected static ?string $model = WmsOrderCandidate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    protected static ?string $slug = 'wms-order-for-jx';

    /**
     * 発注確定済みと同じデータ領域のため、権限は発注確定済みのものを流用する。
     */
    protected static string $permissionResource = 'wms-order-confirmed';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_ORDER_FOR_JX->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_ORDER_FOR_JX->label();
    }

    public static function getModelLabel(): string
    {
        return 'JX発注データ作成';
    }

    public static function getPluralModelLabel(): string
    {
        return 'JX発注データ作成';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_ORDER_FOR_JX->sort();
    }

    public static function getEloquentQuery(): Builder
    {
        // 発注確定済み（CONFIRMED）と送信済み（EXECUTED）を表示
        return parent::getEloquentQuery()
            ->whereIn('status', [CandidateStatus::CONFIRMED, CandidateStatus::EXECUTED])
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('order_channel')
                    ->orWhere('order_channel', OrderChannel::EOS->value);
            })
            ->with([
                'warehouse',
                'item',
                'contractor',
                'modifiedByUser',
                'jxDocument',
            ]);
    }

    public static function table(Table $table): Table
    {
        return WmsOrderConfirmedTable::configure($table, forJx: true);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsOrderForJx::route('/'),
        ];
    }
}
