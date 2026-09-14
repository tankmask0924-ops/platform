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
        Schema::create('merchant_balance_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('merchant_id');
            $table->string('type', 24)->comment('recharge/freeze/deduct/unfreeze/supplement_deduct/refund/adjustment/rebate_settle/rebate_clawback');
            $table->decimal('amount', 10, 2);
            $table->decimal('available_before', 10, 2);
            $table->decimal('available_after', 10, 2);
            $table->decimal('frozen_before', 10, 2);
            $table->decimal('frozen_after', 10, 2);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('rebate_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('operator_id')->nullable();
            // 幂等生成列：只在受限的 type 上才有值，其余为 NULL；MySQL 唯一索引不把多个 NULL 当重复，
            // 借此让 refund/supplement_deduct 之类允许多条，deduct/unfreeze/rebate_settle/rebate_clawback 各自只能一条。
            $table->string('dedupe_order_key', 80)->nullable()->storedAs(
                "CASE WHEN `type` IN ('deduct','unfreeze') THEN CONCAT(`order_id`,'-',`type`) ELSE NULL END"
            );
            $table->string('dedupe_rebate_key', 80)->nullable()->storedAs(
                "CASE WHEN `type` IN ('rebate_settle','rebate_clawback') THEN CONCAT(`rebate_id`,'-',`type`) ELSE NULL END"
            );
            $table->dateTime('created_at');

            $table->index(['merchant_id', 'created_at']);
            $table->index('order_id');
            $table->index('rebate_id');
            $table->unique('dedupe_order_key');
            $table->unique('dedupe_rebate_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_balance_logs');
    }
};
