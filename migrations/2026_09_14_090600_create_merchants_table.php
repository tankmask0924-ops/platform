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
        Schema::create('merchants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('type', 16)->comment('company 企业 / individual 个人');
            $table->string('phone', 20)->nullable()->unique()->comment('登录手机号');
            $table->string('email', 128)->nullable()->unique()->comment('登录邮箱');
            $table->string('password', 255)->comment('密码哈希');
            $table->string('status', 16)->default('pending')->comment('pending/active/rejected/disabled');
            $table->unsignedBigInteger('level_id')->nullable();
            $table->string('app_key', 64)->nullable()->unique();
            $table->string('app_secret', 255)->nullable()->comment('加密存储');
            $table->dateTime('app_secret_reset_at')->nullable();
            $table->json('ip_whitelist')->nullable();
            $table->decimal('available_balance', 10, 2)->default(0);
            $table->decimal('frozen_balance', 10, 2)->default(0);
            $table->dateTime('debt_since')->nullable()->comment('可用余额变为负数的起始时间');
            $table->datetimes();

            $table->index('level_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
