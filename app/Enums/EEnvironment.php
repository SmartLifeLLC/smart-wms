<?php


namespace App\Enums;


namespace App\Enums;


enum EEnvironment: string
{
    case PRODUCTION = 'production';
    case DEVELOPMENT = 'development';
    case LOCAL = 'local';

    case STAGING = 'staging';

    case TESTING = 'testing';

    public function name() : string
    {
        return match($this) {
            self::PRODUCTION => '本番環境',
            self::DEVELOPMENT => '開発環境',
            self::LOCAL => 'ローカル',
            self::STAGING => 'ステージング',
            self::TESTING => 'テスト',
        };
    }

    public static function notProductions() : array
    {
        return collect(self::cases())->reject(
            fn($env) => $env == self::PRODUCTION
        )->map(
            fn($env) => $env->value
        )->toArray();
    }
}
