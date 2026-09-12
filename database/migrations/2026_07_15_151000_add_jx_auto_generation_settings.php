<?php

use App\Enums\AutoOrder\TransmissionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    private const SETTINGS_INDEX = 'idx_wms_contractor_jx_auto_generation';

    public function up(): void
    {
        Schema::connection($this->connection)->table('wms_contractor_settings', function (Blueprint $table) {
            if (! Schema::connection($this->connection)->hasColumn('wms_contractor_settings', 'is_jx_auto_generation_enabled')) {
                $table->boolean('is_jx_auto_generation_enabled')
                    ->default(false)
                    ->after('is_auto_transmission')
                    ->comment('JX発注データ自動生成フラグ');
            }

            if (! Schema::connection($this->connection)->hasColumn('wms_contractor_settings', 'jx_generation_time')) {
                $table->string('jx_generation_time', 5)
                    ->nullable()
                    ->after('is_jx_auto_generation_enabled')
                    ->comment('JX発注データ生成時刻（月-土 HH:MM）');
            }

            if (! Schema::connection($this->connection)->hasColumn('wms_contractor_settings', 'jx_generation_cutoff_time')) {
                $table->string('jx_generation_cutoff_time', 5)
                    ->nullable()
                    ->after('jx_generation_time')
                    ->comment('JX発注データ生成対象締め時刻（月-土 HH:MM）');
            }

            if (! Schema::connection($this->connection)->hasColumn('wms_contractor_settings', 'jx_generation_sunday_time')) {
                $table->string('jx_generation_sunday_time', 5)
                    ->nullable()
                    ->after('jx_generation_cutoff_time')
                    ->comment('JX発注データ生成時刻（日曜 HH:MM）');
            }

            if (! Schema::connection($this->connection)->hasColumn('wms_contractor_settings', 'jx_generation_sunday_cutoff_time')) {
                $table->string('jx_generation_sunday_cutoff_time', 5)
                    ->nullable()
                    ->after('jx_generation_sunday_time')
                    ->comment('JX発注データ生成対象締め時刻（日曜 HH:MM）');
            }
        });

        if (! $this->indexExists('wms_contractor_settings', self::SETTINGS_INDEX)) {
            Schema::connection($this->connection)->table('wms_contractor_settings', function (Blueprint $table) {
                $table->index(
                    ['transmission_type', 'is_jx_auto_generation_enabled', 'transmission_contractor_id'],
                    self::SETTINGS_INDEX
                );
            });
        }

        if (! Schema::connection($this->connection)->hasTable('wms_jx_order_generation_runs')) {
            Schema::connection($this->connection)->create('wms_jx_order_generation_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('representative_contractor_id')->comment('JX生成代表発注先ID');
                $table->date('target_date')->comment('生成対象日');
                $table->string('generation_time', 5)->comment('判定に使用した生成時刻');
                $table->string('cutoff_time', 5)->comment('判定に使用した締め時刻');
                $table->string('status', 20)->default('RUNNING')->comment('RUNNING/SUCCESS/FAILED/SKIPPED');
                $table->unsignedInteger('candidate_count')->default(0)->comment('抽出候補数');
                $table->unsignedInteger('eligible_candidate_count')->default(0)->comment('生成対象候補数');
                $table->unsignedInteger('adjusted_candidate_count')->default(0)->comment('入荷予定日補正候補数');
                $table->unsignedInteger('generated_document_count')->default(0)->comment('生成JXドキュメント数');
                $table->unsignedInteger('generated_order_count')->default(0)->comment('生成発注数');
                $table->json('summary')->nullable()->comment('実行サマリー');
                $table->text('error_message')->nullable()->comment('エラー内容');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->unique(['representative_contractor_id', 'target_date'], 'uq_jx_generation_rep_date');
                $table->index(['target_date', 'status'], 'idx_jx_generation_date_status');
            });
        }

        DB::connection($this->connection)
            ->table('wms_contractor_settings')
            ->where('transmission_type', TransmissionType::JX_FINET->value)
            ->update([
                'is_jx_auto_generation_enabled' => true,
                'jx_generation_time' => '13:30',
                'jx_generation_cutoff_time' => '13:20',
                'jx_generation_sunday_time' => '23:30',
                'jx_generation_sunday_cutoff_time' => '23:00',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_jx_order_generation_runs');

        if ($this->indexExists('wms_contractor_settings', self::SETTINGS_INDEX)) {
            Schema::connection($this->connection)->table('wms_contractor_settings', function (Blueprint $table) {
                $table->dropIndex(self::SETTINGS_INDEX);
            });
        }

        Schema::connection($this->connection)->table('wms_contractor_settings', function (Blueprint $table) {
            $columns = [
                'is_jx_auto_generation_enabled',
                'jx_generation_time',
                'jx_generation_cutoff_time',
                'jx_generation_sunday_time',
                'jx_generation_sunday_cutoff_time',
            ];

            foreach ($columns as $column) {
                if (Schema::connection($this->connection)->hasColumn('wms_contractor_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = config("database.connections.{$this->connection}.database");

        return DB::connection($this->connection)
            ->table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
