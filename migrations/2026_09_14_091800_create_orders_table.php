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
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('order_no', 32)->unique()->comment('平台订单号，对外展示');
            $table->unsignedBigInteger('merchant_id');
            $table->string('merchant_order_no', 64);
            $table->string('business_line', 16)->comment('recharge/card/movie/express');
            $table->string('status', 16)->default('processing')->comment('processing/success/failed/cancelled/abnormal/refunded');
            $table->decimal('sale_price', 10, 2)->comment('售价快照');
            $table->decimal('cost_price', 10, 2)->comment('成本快照');
            $table->unsignedBigInteger('supplier_id')->nullable()->comment('最终成交/最后尝试的供应商');
            $table->string('supplier_order_no', 128)->nullable();
            $table->decimal('frozen_amount', 10, 2);
            $table->decimal('deducted_amount', 10, 2)->nullable();
            $table->decimal('refunded_amount', 10, 2)->default(0);
            $table->string('callback_url', 255);
            $table->string('fail_reason', 255)->nullable();
            $table->dateTime('completed_at')->nullable()->comment('订单完成时间，返佣起算点');
            $table->dateTime('finished_at')->nullable()->comment('进入终态的时间');
            $table->datetimes();

            $table->unique(['merchant_id', 'merchant_order_no']);
            $table->index(['merchant_id', 'status', 'created_at']);
            $table->index(['business_line', 'status']);
            $table->index(['supplier_id', 'status']);
            $table->index(['status', 'completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
