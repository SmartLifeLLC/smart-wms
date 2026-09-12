<?php

namespace App\Services\JX;

use App\Models\WmsJxTransmissionLog;
use App\Models\WmsOrderJxSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * JXドキュメント受信サービス
 *
 * GetDocument → 保存 → ConfirmDocument の一連の流れを処理
 */
class JxDocumentReceiver
{
    protected JxClient $client;

    protected WmsOrderJxSetting $setting;

    protected string $storageDisk = 's3';

    protected string $storageDirectory = 'jx-received';

    protected string $environment = WmsJxTransmissionLog::ENV_PRODUCTION;

    protected ?string $lastError = null;

    public function __construct(WmsOrderJxSetting $setting)
    {
        $this->setting = $setting;
        $this->client = new JxClient($setting);
    }

    /**
     * 環境区分を設定
     */
    public function setEnvironment(string $environment): self
    {
        $this->environment = $environment;

        return $this;
    }

    /**
     * ストレージディスクを設定
     */
    public function setStorageDisk(string $disk): self
    {
        $this->storageDisk = $disk;

        return $this;
    }

    /**
     * ストレージディレクトリを設定
     */
    public function setStorageDirectory(string $directory): self
    {
        $this->storageDirectory = $directory;

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * ドキュメントを受信（全件取得）
     *
     * @return Collection<JxReceivedDocument>
     */
    public function receiveAll(): Collection
    {
        $documents = collect();
        $maxIterations = 100; // 無限ループ防止
        $iteration = 0;

        while ($iteration < $maxIterations) {
            $iteration++;

            $result = $this->receiveSingle();

            if ($result === null) {
                // ドキュメントなし、終了
                break;
            }

            $documents->push($result);

            Log::info('JX Document received', [
                'message_id' => $result->messageId,
                'document_type' => $result->documentType,
                'iteration' => $iteration,
            ]);
        }

        Log::info('JX Document receive completed', [
            'total_documents' => $documents->count(),
        ]);

        return $documents;
    }

    /**
     * 1件のドキュメントを受信
     *
     * @return JxReceivedDocument|null ドキュメントがない場合はnull
     */
    public function receiveSingle(): ?JxReceivedDocument
    {
        // 1. GetDocument リクエスト
        $getResult = $this->client->getDocument();

        if ($getResult->failed()) {
            $this->lastError = $getResult->error ?? 'JX GetDocument failed';

            Log::error('JX GetDocument failed', [
                'error' => $getResult->error,
                'message_id' => $getResult->messageId,
            ]);

            return null;
        }

        // 2. ドキュメントの有無を確認
        if (! $getResult->hasDocument()) {
            $this->lastError = null;

            Log::info('JX GetDocument: No document available');

            return null;
        }

        // 3. データを抽出
        $this->lastError = null;
        $receivedDocument = $this->extractDocument($getResult);

        // 4. ファイルを保存
        $savedPath = $this->saveDocument($receivedDocument);
        $receivedDocument->savedPath = $savedPath;

        // 5. ConfirmDocument を送信（受信ドキュメントのメッセージIDを使用）
        $confirmResult = $this->client->confirmDocument($receivedDocument->messageId);

        if ($confirmResult->failed()) {
            Log::warning('JX ConfirmDocument failed', [
                'error' => $confirmResult->error,
                'received_message_id' => $receivedDocument->messageId,
            ]);
            $receivedDocument->confirmed = false;
        } else {
            $receivedDocument->confirmed = true;
            Log::info('JX ConfirmDocument succeeded', [
                'received_message_id' => $receivedDocument->messageId,
            ]);
        }

        // 6. 受信ログを記録
        $this->logReceive($receivedDocument);

        return $receivedDocument;
    }

    /**
     * 受信ログを記録
     */
    protected function logReceive(JxReceivedDocument $document): void
    {
        try {
            // ディスク情報をパスに含める（例: "s3:jx-received/..." または "local:jx-received/..."）
            $filePathWithDisk = "{$this->storageDisk}:{$document->savedPath}";
            $logSetting = $this->resolveLogJxSetting($document);

            WmsJxTransmissionLog::logReceive(
                jxSettingId: $logSetting->id,
                operationType: JxClient::DOCUMENT_TYPE_GET,
                messageId: $document->messageId,
                success: true,
                documentType: $document->documentType,
                formatType: $document->formatType,
                senderId: $document->senderId,
                receiverId: $document->receiverId,
                dataSize: $document->getDataSize(),
                filePath: $filePathWithDisk,
                httpCode: 200,
                environment: $this->environment,
            );
        } catch (\Exception $e) {
            Log::warning('Failed to log JX receive', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * JX受信は共通クライアントIDで全仕入先分が返るため、DAT内のFINETコードでログのJX設定を決める。
     */
    protected function resolveLogJxSetting(JxReceivedDocument $document): WmsOrderJxSetting
    {
        $finetCode = JxFinetWrapper::detectReceiverStationCode($document->data);

        if ($finetCode === null) {
            return $this->setting;
        }

        $query = WmsOrderJxSetting::query()
            ->active()
            ->where('receiver_station_code', $finetCode);

        if ($this->setting->jx_client_id !== null && $this->setting->jx_client_id !== '') {
            $query->where('jx_client_id', $this->setting->jx_client_id);
        }

        $detectedSetting = $query->first();

        if (! $detectedSetting) {
            Log::warning('JX received document FINET code did not match active JX setting', [
                'finet_code' => $finetCode,
                'fallback_jx_setting_id' => $this->setting->id,
                'message_id' => $document->messageId,
            ]);

            return $this->setting;
        }

        if ($detectedSetting->id !== $this->setting->id) {
            Log::info('JX received document setting resolved from FINET code', [
                'finet_code' => $finetCode,
                'initial_jx_setting_id' => $this->setting->id,
                'detected_jx_setting_id' => $detectedSetting->id,
                'message_id' => $document->messageId,
            ]);
        }

        return $detectedSetting;
    }

    /**
     * レスポンスからドキュメントを抽出
     */
    protected function extractDocument(JxClientResult $result): JxReceivedDocument
    {
        $compressType = $result->getCompressType();
        $shouldDecompress = ! empty($compressType) && strtolower($compressType) === 'gzip';

        $data = $result->getDecodedAndDecompressedData($shouldDecompress);

        // 受信ドキュメントのメッセージID（リクエストのmessageIdとは異なる）
        $receivedMessageId = $result->getReceivedMessageId();

        return new JxReceivedDocument(
            messageId: $receivedMessageId ?? $result->messageId,
            data: $data,
            documentType: $result->getDocumentType(),
            formatType: $result->getFormatType(),
            senderId: $result->getSenderId(),
            receiverId: $result->getReceiverId(),
            compressType: $compressType,
            receivedAt: Carbon::now(),
        );
    }

    /**
     * ドキュメントをストレージに保存
     */
    protected function saveDocument(JxReceivedDocument $document): string
    {
        $date = Carbon::today()->format('Y-m-d');
        $timestamp = Carbon::now()->format('YmdHis');
        $docType = $document->documentType ?? 'unknown';

        // ファイル名を生成
        $filename = "{$timestamp}_{$document->messageId}.dat";
        $path = "{$this->storageDirectory}/{$date}/{$docType}/{$filename}";

        // 保存
        Storage::disk($this->storageDisk)->put($path, $document->data ?? '');

        Log::info('JX Document saved', [
            'path' => $path,
            'disk' => $this->storageDisk,
            'size' => strlen($document->data ?? ''),
        ]);

        return $path;
    }
}
