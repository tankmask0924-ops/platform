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
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('business_line', 16)->comment('movie 电影票 / express 快递；话费、卡券直接在 products.sale_price 设置，不用这张表');
            $table->string('rule_type', 16)->comment('fixed 固定金额（成本 + X 元）/ percentage 百分比（成本 ×(1 + X%)）');
            $table->decimal('value', 10, 4)->comment('fixed 时为金额（元）；percentage 时为比例（0.0500 = 5%）。四舍五入到分在应用层做');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->datetimes();

            // 每条业务线永远只有一条当前生效的规则（database-design.md 4.13）
            $table->unique('business_line');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};
