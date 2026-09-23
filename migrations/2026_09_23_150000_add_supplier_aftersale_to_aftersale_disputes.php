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
        // 争议提交给供应商售后（requirements.md 7.7「供应商支持售后接口时，客服可直接通过接口提交给供应商并接收处理结果」）
        Schema::table('aftersale_disputes', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->after('merchant_id')->comment('提交给哪家供应商售后');
            $table->string('supplier_aftersale_no', 64)->nullable()->after('supplier_id')->comment('供应商售后单号');
            $table->string('supplier_aftersale_status', 16)->nullable()->after('supplier_aftersale_no')->comment('processing 处理中 / completed 处理完成 / terminated 终止');
            $table->string('supplier_aftersale_reply', 500)->nullable()->after('supplier_aftersale_status')->comment('供应商处理说明');
            $table->dateTime('supplier_aftersale_submitted_at')->nullable()->after('supplier_aftersale_reply');
            $table->dateTime('supplier_aftersale_updated_at')->nullable()->after('supplier_aftersale_submitted_at');

            $table->index(['supplier_id', 'supplier_aftersale_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aftersale_disputes', function (Blueprint $table) {
            $table->dropIndex(['supplier_id', 'supplier_aftersale_no']);
            $table->dropColumn([
                'supplier_id', 'supplier_aftersale_no', 'supplier_aftersale_status', 'supplier_aftersale_reply',
                'supplier_aftersale_submitted_at', 'supplier_aftersale_updated_at',
            ]);
        });
    }
};
