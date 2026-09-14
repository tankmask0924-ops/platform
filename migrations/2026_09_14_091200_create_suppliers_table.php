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
        Schema::create('suppliers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 64);
            $table->string('code', 32)->unique()->comment('创建后不可改，用于回调地址 /notify/{code}');
            $table->string('business_line', 16)->comment('单选：recharge/card/movie/express');
            $table->string('driver', 32)->comment('kasushou/yunyang/mango');
            $table->text('config')->comment('接口地址、账号、密钥等，加密存储');
            $table->string('status', 16)->default('active')->comment('active 启用 / disabled 停用');
            $table->decimal('balance', 10, 2)->nullable()->comment('最近一次查询到的预存款余额缓存');
            $table->dateTime('balance_synced_at')->nullable();
            $table->decimal('balance_warning_threshold', 10, 2)->nullable();
            $table->string('contact', 128)->nullable();
            $table->string('settlement_info', 255)->nullable();
            $table->text('remark')->nullable();
            $table->datetimes();

            $table->index(['business_line', 'status']);
            $table->index('driver');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
