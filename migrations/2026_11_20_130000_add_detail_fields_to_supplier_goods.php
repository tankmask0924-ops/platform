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
        Schema::table('supplier_goods', function (Blueprint $table) {
            $table->json('attach_template')->nullable()->after('stock')->comment('下单参数模板 [{key,name,type}]，卡密商品为空数组；为空表示还没查过详情');
            $table->unsignedInteger('min_quantity')->nullable()->after('attach_template')->comment('最小购买数量');
            $table->unsignedInteger('max_quantity')->nullable()->after('min_quantity')->comment('最大购买数量');
            $table->boolean('can_back')->nullable()->after('max_quantity')->comment('是否支持撤单');
            $table->json('sale_restrictions')->nullable()->after('can_back')->comment('可售/禁售渠道、销售限价、余额限价，不展示给商户');
            $table->dateTime('detail_synced_at')->nullable()->after('synced_at')->comment('最近一次查商品详情的时间');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_goods', function (Blueprint $table) {
            $table->dropColumn(['attach_template', 'min_quantity', 'max_quantity', 'can_back', 'sale_restrictions', 'detail_synced_at']);
        });
    }
};
