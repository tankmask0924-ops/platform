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
        Schema::create('card_brands', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 64)->comment('品牌名称，如 爱奇艺、腾讯视频');
            $table->unsignedInteger('sort')->default(0)->comment('排序，数字小的在前');
            $table->string('status', 16)->default('active')->comment('active 启用 / disabled 停用（停用后新商品不能选）');
            $table->datetimes();

            $table->unique('name');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('brand_id')->nullable()->after('card_type')->comment('卡券专用：card_brands.id');
            $table->index('brand_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['brand_id']);
            $table->dropColumn('brand_id');
        });
        Schema::dropIfExists('card_brands');
    }
};
