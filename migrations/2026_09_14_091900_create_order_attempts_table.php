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
        Schema::create('order_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedInteger('attempt_no')->comment('同一订单从 1 递增');
            $table->string('result', 16)->comment('success/failed/processing/unknown');
            $table->string('fail_reason', 255)->nullable();
            $table->json('request_snapshot')->nullable();
            $table->json('response_snapshot')->nullable();
            $table->datetimes();

            $table->unique(['order_id', 'attempt_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_attempts');
    }
};
