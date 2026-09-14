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
        Schema::create('order_recharges', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->string('recharge_account', 32)->nullable()->comment('充值账号/手机号，卡密类可为空');
            $table->string('card_no', 255)->nullable()->comment('加密存储');
            $table->string('card_pwd', 255)->nullable()->comment('加密存储');
            $table->decimal('rebate_amount', 10, 2)->comment('商品返佣金额快照');

            $table->primary('order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_recharges');
    }
};
