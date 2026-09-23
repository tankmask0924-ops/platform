<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration {
    /**
     * 电影票基础数据缓存（database-design.md 4.11）：城市、区县、影院走批量全量同步 +
     * 影院更新回调增量同步。场次缓存表 movie_showtime_caches 是可选的，申请到批量拉取场次
     * 权限才建，现在场次全部实时转发。
     */
    public function up(): void
    {
        Schema::create('movie_cities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('city_id', 32);
            $table->string('city_name', 64);
            $table->string('first_letter', 4)->nullable();
            $table->boolean('is_hot')->default(false);
            $table->dateTime('synced_at');

            $table->unique(['supplier_id', 'city_id']);
        });

        Schema::create('movie_regions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('city_id', 32);
            $table->string('region_id', 32);
            $table->string('region_name', 64);
            $table->dateTime('synced_at');

            $table->unique(['supplier_id', 'city_id', 'region_id']);
        });

        Schema::create('movie_cinemas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('cinema_id', 32);
            $table->string('cinema_code', 32)->nullable()->comment('影院专资编码');
            $table->string('cinema_name', 128);
            $table->string('city_id', 32);
            $table->string('region_id', 32)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('tel', 64)->nullable();
            $table->decimal('longitude', 10, 6)->nullable();
            $table->decimal('latitude', 10, 6)->nullable();
            $table->json('service_info')->nullable();
            $table->dateTime('synced_at')->comment('最近一次批量同步时间');
            $table->dateTime('callback_synced_at')->nullable()->comment('最近一次影院更新回调增量同步时间');

            $table->unique(['supplier_id', 'cinema_id']);
            $table->index(['city_id', 'region_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_cinemas');
        Schema::dropIfExists('movie_regions');
        Schema::dropIfExists('movie_cities');
    }
};
