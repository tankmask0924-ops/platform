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
        // 快递工单，客服代提交（database-design.md 4.9，requirements.md 7.2「售后不对商户开放」）
        Schema::create('express_workorders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->comment('外键 orders.id');
            $table->unsignedBigInteger('supplier_id')->comment('提交给哪家供应商（订单当时的供应商）');
            $table->string('type', 24)->comment('weight_verify 重量核实 / claim 理赔 / cancel 取消 / cod 现结到付 / urge_pickup 催取件 / urge_transport 催物流 / urge_delivery 催派送');
            $table->string('status', 16)->comment('processing 处理中 / completed 已完成 / rejected 已驳回');
            $table->string('content', 500)->comment('提交给供应商的工单内容');
            $table->string('supplier_workorder_no', 64)->nullable()->comment('供应商侧工单号');
            $table->unsignedBigInteger('submitted_by')->comment('外键 admin_users.id，代提交的客服');
            // 供应商工单回调没有签名，下面三列只是"供应商说了什么"，展示给客服核实，不直接动钱
            $table->string('supplier_reply', 500)->nullable()->comment('供应商最近一次回复（回调原文摘要，未验证）');
            $table->decimal('supplier_amount', 10, 2)->nullable()->comment('供应商回调里的赔付/退回金额（未验证）');
            $table->dateTime('supplier_replied_at')->nullable();
            $table->string('result_remark', 255)->nullable()->comment('客服填写的处理结果');
            $table->decimal('claim_amount', 10, 2)->nullable()->comment('核实后调账给商户的理赔金额（type=claim）');
            $table->unsignedBigInteger('resolved_by')->nullable()->comment('外键 admin_users.id，结单的客服');
            $table->dateTime('resolved_at')->nullable();
            $table->datetimes();

            $table->index(['order_id', 'type']);
            $table->index(['status', 'id']);
            $table->index(['supplier_id', 'supplier_workorder_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('express_workorders');
    }
};
