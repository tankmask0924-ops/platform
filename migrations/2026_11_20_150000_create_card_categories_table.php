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
        Schema::create('card_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 64)->comment('卡券分类，如 影音会员、游戏点卡');
            $table->unsignedInteger('sort')->default(0)->comment('排序，数字小的在前');
            $table->string('status', 16)->default('active')->comment('active 启用 / disabled 停用（停用后新建品牌时选不到）');
            $table->datetimes();

            $table->unique('name');
        });

        Schema::table('card_brands', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->after('id')->comment('card_categories.id，新建品牌必填');
            $table->index('category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('card_brands', function (Blueprint $table) {
            $table->dropIndex(['category_id']);
            $table->dropColumn('category_id');
        });
        Schema::dropIfExists('card_categories');
    }
};
