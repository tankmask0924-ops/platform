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
        Schema::create('supplier_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('supplier_product_code', 64);
            $table->decimal('cost_price', 10, 2);
            $table->unsignedInteger('priority');
            $table->string('status', 16)->default('active')->comment('active/paused/banned');
            $table->integer('stock')->nullable()->comment('为空表示不限');
            $table->json('param_mapping')->nullable();
            $table->json('sale_restrictions')->nullable()->comment('不展示给商户');
            $table->dateTime('synced_at')->nullable();
            $table->datetimes();

            $table->index(['product_id', 'priority']);
            $table->index(['supplier_id', 'status']);
            $table->unique(['product_id', 'supplier_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
    }
};
