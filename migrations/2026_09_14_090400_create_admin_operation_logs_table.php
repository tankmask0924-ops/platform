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
        Schema::create('admin_operation_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('admin_user_id');
            $table->string('module', 32)->comment('操作模块：商户/资金/供应商/价格/等级/返佣/系统设置…');
            $table->string('action', 64)->comment('操作类型，如 update_price、adjust_balance');
            $table->string('target_type', 32)->nullable()->comment('操作对象类型，如 merchant、product');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('ip', 45)->nullable();
            $table->dateTime('created_at');

            $table->index(['module', 'target_type', 'target_id']);
            $table->index('admin_user_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_operation_logs');
    }
};
