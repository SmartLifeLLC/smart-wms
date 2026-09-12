<?php

namespace App\Filament\Resources\WmsIncomingReceivedData\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsIncomingReceivedData\WmsIncomingReceivedDataResource;
use App\Models\WmsIncomingReceivedFile;
use App\Services\AutoOrder\IncomingParsers\ActCsvIncomingParser;
use App\Services\AutoOrder\IncomingReceiveService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsIncomingReceivedData extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsIncomingReceivedDataResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('uploadJxFile')
                ->label('JXデータ取込')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->modalHeading('JX納品データの取込')
                ->modalDescription('JX納品伝票データファイル（Shift_JIS固定長128バイト）をアップロードしてください。FINETラッパーの有無は自動判定されます。')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('アップロード')->color('danger'))
                ->modalCancelActionLabel('アップロードせず閉じる')
                ->schema([
                    FileUpload::make('jx_file')
                        ->label('JXデータファイル')
                        ->required()
                        ->disk('local')
                        ->directory('temp/jx-incoming')
                        ->acceptedFileTypes(['text/plain', 'application/octet-stream', '.dat', '.txt'])
                        ->maxSize(10240),
                ])
                ->action(function (array $data) {
                    $filePath = storage_path('app/private/'.$data['jx_file']);
                    if (! file_exists($filePath)) {
                        // Filament 4ではprivateなしのパスの場合もある
                        $filePath = storage_path('app/'.$data['jx_file']);
                    }

                    if (! file_exists($filePath)) {
                        Notification::make()
                            ->title('ファイルが見つかりません')
                            ->danger()
                            ->send();

                        return;
                    }

                    $content = file_get_contents($filePath);
                    $filename = basename($data['jx_file']);

                    $service = app(IncomingReceiveService::class);

                    try {
                        $file = $service->parseJxData($content, $filename);

                        Notification::make()
                            ->title('JXデータを取り込みました')
                            ->body("伝票数: {$file->parsed_slip_count}件 / 明細数: {$file->parsed_detail_count}件")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('取込エラー')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    } finally {
                        // 一時ファイル削除
                        @unlink($filePath);
                    }
                }),

            Action::make('uploadCsvFile')
                ->label('CSVデータ取込')
                ->icon('heroicon-o-document-arrow-up')
                ->color('success')
                ->modalHeading('CSVデータの取込')
                ->modalDescription('発注先出荷実績CSVファイル（Shift_JIS、カンマ区切り）をアップロードしてください。')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('アップロード')->color('danger'))
                ->modalCancelActionLabel('アップロードせず閉じる')
                ->schema([
                    Radio::make('contractor_id')
                        ->label('発注先')
                        ->options([
                            '1497' => 'アクト中食（1497）',
                        ])
                        ->required()
                        ->default('1497'),

                    FileUpload::make('csv_file')
                        ->label('CSVファイル')
                        ->required()
                        ->disk('local')
                        ->directory('temp/csv-incoming')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/octet-stream', '.csv'])
                        ->maxSize(10240),
                ])
                ->action(function (array $data) {
                    $filePath = storage_path('app/private/'.$data['csv_file']);
                    if (! file_exists($filePath)) {
                        $filePath = storage_path('app/'.$data['csv_file']);
                    }

                    if (! file_exists($filePath)) {
                        Notification::make()
                            ->title('ファイルが見つかりません')
                            ->danger()
                            ->send();

                        return;
                    }

                    $content = file_get_contents($filePath);
                    $filename = basename($data['csv_file']);
                    $contractorCode = $data['contractor_id'];
                    $contractorId = \App\Models\Sakemaru\Contractor::where('code', $contractorCode)->value('id');

                    try {
                        $parser = new ActCsvIncomingParser;
                        $file = $parser->parse($content, $filename, $contractorId);

                        Notification::make()
                            ->title('CSVデータを取り込みました')
                            ->body("伝票数: {$file->parsed_slip_count}件 / 明細数: {$file->parsed_detail_count}件")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('取込エラー')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    } finally {
                        @unlink($filePath);
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('slips')
                ->orderBy('id', 'desc')
            );
    }

    public function getPresetViews(): array
    {
        return [
            'all' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(),

            'pending' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', WmsIncomingReceivedFile::STATUS_PENDING))
                ->favorite()
                ->label('未照合'),

            'matched' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', WmsIncomingReceivedFile::STATUS_MATCHED))
                ->favorite()
                ->label('照合済み'),

            'applied' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', WmsIncomingReceivedFile::STATUS_APPLIED))
                ->favorite()
                ->label('適用済み'),

            'skipped' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', WmsIncomingReceivedFile::STATUS_SKIPPED))
                ->favorite()
                ->label('対象外'),
        ];
    }
}
