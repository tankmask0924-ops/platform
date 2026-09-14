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
        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('business_line', 16)->comment('recharge/card');
            $table->string('name', 128)->comment('如"移动 100 元快充"');
            $table->string('operator', 16)->nullable()->comment('话费专用：mobile/unicom/telecom');
            $table->string('province', 32)->nullable();
            $table->string('charge_speed', 16)->nullable()->comment('话费专用：fast/slow');
            $table->string('card_type', 16)->nullable()->comment('卡券专用：direct/card_secret');
            $table->decimal('face_value', 10, 2);
            $table->decimal('sale_price', 10, 2);
            $table->decimal('rebate_amount', 10, 2)->default(0);
            $table->string('applicable_region', 64)->nullable();
            $table->string('status', 16)->default('off_shelf')->comment('on_shelf/off_shelf');
            $table->datetimes();

            $table->index(['business_line', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
