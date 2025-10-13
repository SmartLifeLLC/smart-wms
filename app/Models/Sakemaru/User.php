<?php

namespace App\Models\Sakemaru;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable
{

    protected $connection = 'sakemaru';
    protected $table = 'users';
    protected $primaryKey = 'id';
    public $timestamps = false;
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'name',
        'kana_name',
        'email',
        'code',
        'default_branch_id',
        'default_warehouse_id',
        'permission_ship_rare_item',
        'invalidation_date',
        'is_active',
        'password',
        'created_at',
        'updated_at',
        'is_created_from_data_transfer',
        'creator_id',
        'last_updater_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
    public function branch() : BelongsTo
    {
        return $this->belongsTo(Branch::class,'default_branch_id', 'id');
    }
    public function warehouse() : BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id', 'id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id', 'id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_updater_id', 'id');
    }

    protected function mainRole(): Attribute
    {
        $role = $this->roles->first()->display_name ?? '';
        return Attribute::make(
            get: fn () => $role ?: '',
        );
    }

    public static function getTableName(): string
    {
        return with(new static)->getTable();
    }

    public function newQuery() : Builder
    {
        $query = parent::newQuery();
        $query = $query->where("users.is_active", true);
        // 一時的に消す
//        if (hasColumn($table_name, 'client_id') && !config('app.is_from_admin')) {
//            $client_id = auth()->user()?->client_id;
//            $query = $query->where("{$table_name}.client_id", $client_id);
//        }
        return $query;
    }

    public static function hasColumn(string $col): bool
    {
        return Schema::hasColumn(static::getTableName(), $col);
    }

    public static function getCodeIds($client_id): array
    {
        return User::where('client_id', $client_id)->pluck('id', 'code')->toArray();
    }

    public static function deleteAllForClient($client_id): int
    {
        \DB::table('model_has_roles')->whereIn('model_id',function($query) use ($client_id){
            $query->select('id')->from('users')->where('client_id',$client_id);
        })->delete();
        $query = \DB::table('users')->where('client_id',$client_id);
        $target_row_count = $query->count();
        $query->delete();
        return $target_row_count;

    }


    public function permissionShipRareItemAttribute($value): bool
    {
        return $value === 1;
    }

}
