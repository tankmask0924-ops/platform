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
        Schema::create('aftersale_disputes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('merchant_id');
            $table->string('status', 16)->default('processing')->comment('processing/rejected/confirmed');
            $table->json('evidence')->nullable()->comment('客服核实凭证');
            $table->unsignedBigInteger('handler_id')->nullable();
            $table->string('result_remark', 255)->nullable();
            $table->dateTime('submitted_at');
            $table->dateTime('resolved_at')->nullable();
            $table->datetimes();

            $table->unique('order_id');
            $table->index(['merchant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aftersale_disputes');
    }
};
