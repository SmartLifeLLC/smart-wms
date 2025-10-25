<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     *
     * Create wms_locations table to extend core locations table with WMS-specific attributes:
     * - picking_unit_type: Determines if location stores CASE, PIECE, or BOTH
     * - walking_order: Optimizes picker routing (aisle -> rack -> level)
     * - zone_code: Temperature zone classification (常温/冷蔵/冷凍)
     * - aisle, rack, level: Physical warehouse structure
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('location_id')->comment('locations.id への1:1リンク');

            // WMS-specific attributes
            $table->enum('picking_unit_type', ['CASE', 'PIECE', 'BOTH'])
                ->default('BOTH')
                ->comment('引当可能な単位: ケース／バラ／両方');

            $table->integer('walking_order')
                ->nullable()
                ->comment('倉庫内動線順序（通路→棚→段）。数値が小さいほど優先');

            $table->string('zone_code', 50)
                ->nullable()
                ->comment('温度帯・エリア区分（常温／冷蔵／冷凍など）');

            // Physical warehouse structure
            $table->string('aisle', 20)
                ->nullable()
                ->comment('通路番号');

            $table->string('rack', 20)
                ->nullable()
                ->comment('棚番号');

            $table->string('level', 20)
                ->nullable()
                ->comment('段番号');

            $table->timestamps();

            // Indexes
            $table->unique('location_id', 'uniq_location_id');
            $table->index('picking_unit_type', 'idx_picking_unit_type');
            $table->index('walking_order', 'idx_walking_order');
            $table->index('zone_code', 'idx_zone_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_locations');
    }
};
