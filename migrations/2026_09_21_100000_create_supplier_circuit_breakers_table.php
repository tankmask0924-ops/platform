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
        Schema::create('supplier_circuit_breakers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_id');
            // 0 表示整个供应商熔断，非 0 表示只熔断这个供应商的这个商品。
            // 刻意不用 NULL：MySQL 唯一索引不拦截重复的 NULL，用 NULL 表示"整个供应商"
            // 会让同一供应商插入多条全局熔断行而不报错（database-design.md 4.4）。
            $table->unsignedBigInteger('product_id')->default(0)->comment('0 = 整个供应商');
            $table->string('status', 16)->default('normal')->comment('normal 正常 / paused 熔断中');
            $table->dateTime('paused_until')->nullable()->comment('暂停截止时间，到期自动恢复；手动暂停为 NULL 表示无限期');
            $table->string('triggered_reason', 255)->nullable()->comment('触发原因（失败率快照或人工备注）');
            $table->datetimes();

            $table->unique(['supplier_id', 'product_id']);
            $table->index(['status', 'paused_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_circuit_breakers');
    }
};
