<?php

namespace App\Filament\Resources\WmsWarehouseTransferCandidates;

use App\Filament\Resources\WmsWarehouseTransferCandidates\Pages\ListWmsWarehouseTransferCandidates;
use App\Filament\Resources\WmsWarehouseTransferCandidates\Pages\ViewWmsWarehouseTransferCandidate;
use App\Filament\Resources\WmsWarehouseTransferCandidates\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\WmsWarehouseTransferCandidates\Schemas\WmsWarehouseTransferCandidateInfolist;
use App\Filament\Resources\WmsWarehouseTransferCandidates\Tables\WmsWarehouseTransferCandidatesTable;
use App\Filament\Support\AdminResource;
use App\Models\WmsWarehouseTransferCandidate;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Sakemaru\Auth\Services\PermissionService;

/**
 * 倉庫移動候補（HANDY起点）
 *
 * メニュー: 在庫管理 => 倉庫移動候補
 * 権限: wms.wms-warehouse-transfer-candidate.{view,create,edit,delete,confirm,cancel}
 */
class WmsWarehouseTransferCandidateResource extends AdminResource
{
    protected static ?string $model = WmsWarehouseTransferCandidate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 31;

    public static function getNavigationGroup(): ?string
    {
        return '在庫管理';
    }

    public static function getNavigationLabel(): string
    {
        return '倉庫移動候補';
    }

    public static function getModelLabel(): string
    {
        return '倉庫移動候補';
    }

    public static function getPluralModelLabel(): string
    {
        return '倉庫移動候補';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('items')
            ->withSum('items', 'transfer_quantity')
            ->with(['submittedByPicker', 'confirmedByUser']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WmsWarehouseTransferCandidateInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsWarehouseTransferCandidatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsWarehouseTransferCandidates::route('/'),
            'view' => ViewWmsWarehouseTransferCandidate::route('/{record}'),
        ];
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /**
     * 確定権限（在庫移動を実行するため閲覧/編集とは分ける）
     */
    public static function canConfirm(): bool
    {
        return static::hasCustomPermission('confirm');
    }

    public static function canCancel(): bool
    {
        return static::hasCustomPermission('cancel');
    }

    protected static function hasCustomPermission(string $action): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return app(PermissionService::class)->check($user, "wms.wms-warehouse-transfer-candidate.{$action}");
    }
}
