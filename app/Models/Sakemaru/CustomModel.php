<?php

namespace App\Models\Sakemaru;


//use App\Models\Concerns\WithDataTransferDelete;
//use App\Traits\DataTransferModelTrait;
//use App\Traits\UpdatingLogTrait;
use App\Models\Sakemaru\Client;
use App\Models\Sakemaru\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;


abstract class CustomModel extends SakemaruModel
{
//    use UpdatingLogTrait;
//    use DataTransferModelTrait;
    protected bool $is_active_activate = true;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->is_active_activate = $attributes['is_active_activate'] ?? true;
    }

    public function onOffIsActive($on_off): self
    {
        $this->is_active_activate = $on_off;
        return $this;
    }

    public static function cast($obj): self|static
    {
        if (!($obj instanceof static)) {
            throw new \InvalidArgumentException("{$obj} is not instance of CastObject");
        }
        return $obj;
    }

    public static function getTableName(): string
    {
        return with(new static)->getTable();
    }

    public static function hasColumn(string $col): bool
    {
        return hasColumn(static::getTableName(), $col);
    }


    public function newQuery(): Builder
    {
        $query = parent::newQuery();
        $table_name = $this->getTable();

        if ($this->is_active_activate && hasColumn($table_name, 'is_active')) {
            $query = $query->where("{$table_name}.is_active", true);
        }
        // 一時的に消す
//        if (hasColumn($table_name, 'client_id') && !config('app.is_from_admin')) {
//            $client_id = auth()->user()?->client_id;
//            $query = $query->where("{$table_name}.client_id", $client_id);
//        }
        return $query;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    // 以下作成者カラムが存在する場合
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id', 'id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_updater_id', 'id');
    }

    protected function creatorName(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->creator?->name ?: '管理者',
        );
    }

    protected function updaterName(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->updater?->name ?: '管理者',
        );
    }

    public static function getBaseQuery($client_id, $conditions = [], $only_is_active = true) : Builder
    {
        $class = (static::class);
        $query = (new $class)->onOffIsActive($only_is_active)->where('client_id', $client_id);
        foreach ($conditions as $key => $value) {
            $query = $query->where($key, $value);
        }
        return $query;
    }

    public static function getCodeIds($client_id, $conditions = [], $set_is_active = false): array
    {
        //todo getBaseQueryと共通化
        $class = (static::class);
        $query = (new $class)->onOffIsActive($set_is_active)->where('client_id', $client_id);
        foreach ($conditions as $key => $value) {
            $query = $query->where($key, $value);
        }
        return $query->pluck('id', 'code')->toArray();
    }

    public static function chunkDelete(){

    }

}
