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
        Schema::create('order_expresses', function (Blueprint $table) {
            // 主键兼外键：一笔快递订单只有一行明细（database-design.md 4.7）
            $table->unsignedBigInteger('order_id')->primary();
            $table->string('express_company_code', 32)->comment('平台自己的快递公司渠道编号，不暴露供应商渠道 ID');
            $table->string('express_company_name', 64);
            $table->json('sender_info');
            $table->json('receiver_info');
            $table->json('item_info');
            $table->decimal('weight', 6, 2)->comment('重量（kg）');
            $table->decimal('insured_amount', 10, 2)->nullable()->comment('保价金额');
            $table->string('waybill_no', 64)->nullable()->comment('供应商运单号');
            $table->decimal('estimated_freight', 10, 2)->comment('预估运费成本');
            $table->decimal('frozen_freight', 10, 2)->nullable()->comment('供应商确认下单后返回的冻结运费');
            $table->decimal('actual_freight', 10, 2)->nullable()->comment('实际运费（扣费后）');
            // 实际运费成本对应的、商户实际被收的运费（加价后）。结算时写入，运费再调整时同步更新。
            // 必须存下来而不是每次按加价规则现算：规则改过之后现算的数跟当时实际扣的钱对不上，
            // 费用调整的差额也会跟着算错
            $table->decimal('freight_sale_price', 10, 2)->nullable()->comment('商户侧运费（加价后），结算时写入');
            $table->decimal('actual_insured_fee', 10, 2)->nullable();
            $table->decimal('actual_material_fee', 10, 2)->nullable()->comment('实际耗材费');
            $table->decimal('actual_reverse_fee', 10, 2)->nullable()->comment('实际逆向费');
            $table->string('logistics_status', 16)->default('pending_pickup')->comment('pending_pickup/in_transit/signed/rejected/cancelled');
            $table->dateTime('fee_over_at')->nullable()->comment('供应商完成扣费时间（订单"成功"时刻）');
            $table->dateTime('signed_at')->nullable()->comment('签收时间（订单完成时间来源）');
            $table->decimal('supplier_rebate', 10, 2)->nullable()->comment('供应商返佣（云洋当前无，预留字段）');
            $table->datetimes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_expresses');
    }
};
