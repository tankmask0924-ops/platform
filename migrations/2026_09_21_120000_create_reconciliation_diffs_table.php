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
        Schema::create('reconciliation_diffs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('type', 16)->comment('order 订单对账 / rebate 返佣对账');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('supplier_id');
            $table->date('reconciliation_date')->comment('对账批次所属日期（跑对账任务的日期，不是订单创建日期）');
            $table->string('field', 32)->comment('status / cost_price / rebate_amount');
            $table->string('platform_value', 64)->comment('平台侧记录的值，统一存字符串，按 field 解析');
            $table->string('supplier_value', 64)->comment('供应商侧记录的值');
            $table->decimal('diff_amount', 10, 2)->nullable()->comment('金额类差异的数值，field=status 时为空');
            $table->string('status', 16)->default('open')->comment('open 待处理 / resolved 已处理 / ignored 已忽略');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->string('remark', 255)->nullable()->comment('处理备注');
            $table->dateTime('created_at')->comment('发现时间');

            $table->index(['reconciliation_date', 'type']);
            $table->index(['status', 'created_at']);
            $table->index('order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliation_diffs');
    }
};
