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
        Schema::create('supplier_notify_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('order_id')->nullable()->comment('影院更新回调等非订单类回调为空');
            $table->json('payload');
            $table->boolean('signature_valid')->nullable()->comment('供应商回调无签名时为空');
            $table->boolean('processed')->default(false)->comment('是否已触发查询确认，不代表资金操作已完成');
            $table->dateTime('created_at');

            $table->index(['supplier_id', 'created_at']);
            $table->index('order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_notify_logs');
    }
};
