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
        Schema::create('merchant_rebates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('merchant_id');
            $table->string('business_line', 16);
            $table->unsignedBigInteger('level_id')->comment('下单时商户等级快照');
            $table->decimal('rebate_base', 10, 2);
            $table->string('rebate_base_source', 16)->comment('product/supplier');
            $table->decimal('rebate_rate', 6, 4);
            $table->string('rebate_rate_source', 16)->comment('product_level/level');
            $table->decimal('amount', 10, 2)->comment('rebate_base × rebate_rate，向下取整到分');
            $table->string('status', 16)->default('pending')->comment('pending/settled/voided/clawed_back');
            $table->dateTime('order_completed_at')->nullable();
            $table->dateTime('due_at')->nullable()->comment('order_completed_at + 固定期限');
            $table->dateTime('settled_at')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->dateTime('clawed_back_at')->nullable();
            $table->datetimes();

            $table->unique('order_id');
            $table->index(['status', 'due_at']);
            $table->index(['merchant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_rebates');
    }
};
