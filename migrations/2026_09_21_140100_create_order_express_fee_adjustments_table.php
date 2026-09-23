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
        Schema::create('order_express_fee_adjustments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->string('type', 16)->comment('supplement 补扣 / refund 退回');
            $table->string('item', 16)->comment('freight 运费 / insured 保价费 / material 耗材费 / reverse 逆向费');
            $table->decimal('amount', 10, 2)->comment('调整金额，正数');
            $table->string('reason', 255)->nullable();
            // 写一次不再更新的记录，只有 created_at（同 merchant_balance_logs）
            $table->dateTime('created_at');

            $table->index(['order_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_express_fee_adjustments');
    }
};
