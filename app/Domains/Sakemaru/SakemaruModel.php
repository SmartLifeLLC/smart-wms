<?php

namespace App\Domains\Sakemaru;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SakemaruModel
{
    protected static function retryRequest(callable $request, string $url, int $maxRetry = 3): array
    {
        $retry = 1;
        $response = $request();

        while (!$response->successful()) {
            if ($retry > $maxRetry) {
                Log::error('APIの呼び出しに失敗しました。', [
                    'url' => $url,
                    'response' => $response->reason(),
                    'content' => $response->json(),
                ]);
                return [
                    'success' => false,
                    'error' => $response->reason(),
                    'status' => $response->status(),
                ];
            }

            sleep($retry * 60);
            Log::info('API Retry ' . $retry, [
                'url' => $url,
                'response' => $response->reason(),
                'content' => $response->json(),
            ]);

            $response = $request();
            $retry++;
        }

        return $response->json();
    }

    public static function getData(int $page = 1, array $params = []): array
    {
        return static::retryRequest(
            fn() => static::getResponse($page, $params),
            static::url($page)
        );
    }

    public static function postData(array $data): array
    {
        return static::retryRequest(
            fn() => static::postResponse($data),
            static::postUrl()
        );
    }

    protected static function getResponse(int $page = 1, array $params = []): Response
    {
        $url = static::url($page);
        foreach ($params as $key => $param) {
            $url .= "&{$key}={$param}";
        }

        return Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ])
            ->withToken(static::getApiToken())
            ->get($url);
    }

    protected static function postResponse(array $data): Response
    {
        return Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ])
            ->timeout(300)
            ->withToken(static::getApiToken())
            ->post(static::postUrl(), $data);
    }

    protected static function url(int $page = 1): string
    {
        return '';
    }

    protected static function postUrl(): string
    {
        return '';
    }

    protected static function baseUrl(): string
    {
        $coreUrl = config('app.core_url', env('CORE_URL', 'https://sakemaru-core.test'));
        return rtrim($coreUrl, '/') . '/api';
    }

    protected static function getApiToken(): string
    {
        return config('app.sakemaru_api_token', env('SAKEMARU_API_TOKEN', ''));
    }
}
