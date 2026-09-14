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
        Schema::create('supplier_call_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('action', 32)->comment('place_order/query/query_balance/cancel 等');
            $table->json('request')->comment('卡密类字段打码');
            $table->json('response')->nullable()->comment('卡密类字段打码');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->dateTime('created_at');

            $table->index(['order_id', 'created_at']);
            $table->index(['supplier_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_call_logs');
    }
};
