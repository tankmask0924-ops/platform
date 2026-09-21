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

/*
 * 对账按 `finished_at` 取"某一天进入终态的订单"（App\Dao\OrderDao::listFinishedBetween），
 * 建表时的索引都是 `(..., created_at)` / `(status, completed_at)`，没有一个能走
 * finished_at 的范围条件，每天一次的对账会全表扫 orders。
 *
 * `completed_at` 只有成功的订单才有（返佣起算点），失败/已退款订单为空，不能拿它代替。
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('finished_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['finished_at']);
        });
    }
};
