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
        Schema::create('supplier_goods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('code', 64)->comment('供应商侧商品编码');
            $table->string('name', 128)->nullable()->comment('供应商侧商品名称');
            $table->decimal('face_value', 10, 2)->nullable();
            $table->decimal('cost_price', 10, 2);
            $table->string('status', 16)->default('active')->comment('active/paused/banned');
            $table->integer('stock')->nullable()->comment('为空表示不限');
            $table->dateTime('synced_at')->nullable();
            $table->datetimes();

            $table->unique(['supplier_id', 'code']);
            $table->index(['supplier_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_goods');
    }
};
