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
    public function up(): void
    {
        // 电影票订单明细（database-design.md 4.7），主键兼外键 orders.id
        Schema::create('order_movies', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->primary();
            $table->string('cinema_id', 32)->comment('影院 ID（查询时使用的 ID，非场次记录自带的）');
            $table->string('cinema_name', 128)->nullable();
            $table->string('film_id', 32)->comment('影片 ID（查询时使用的 ID）');
            $table->string('film_name', 128)->nullable();
            $table->string('show_id', 255)->comment('场次 ID（供应商 showid，可能含特殊字符）');
            $table->dateTime('show_time');
            $table->string('area_id', 32)->nullable()->comment('分区 ID，不分区为空');
            $table->json('seats')->comment('座位列表（座位号、行列坐标、情侣座标记）');
            $table->unsignedTinyInteger('seat_count');
            $table->decimal('unit_price', 10, 2)->comment('每张售价快照（成本 + 电影票加价规则）');
            $table->decimal('unit_cost', 10, 2)->comment('每张成本快照（锁座时查到的场次成本）');
            $table->string('mobile', 20)->comment('取票手机号');
            $table->dateTime('lock_expire_at')->comment('锁座有效期截止时间');
            // 确认出票之后锁座就不会再超时，超时释放任务靠这一列区分
            $table->dateTime('confirmed_at')->nullable()->comment('商户确认出票时间');
            $table->json('ticket_codes')->nullable()->comment('取票码/验证码，出票成功后写入');
            $table->decimal('supplier_rebate', 10, 2)->nullable()->comment('供应商返佣（以查询订单详情接口为准）');
            $table->datetimes();

            $table->index('lock_expire_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_movies');
    }
};
