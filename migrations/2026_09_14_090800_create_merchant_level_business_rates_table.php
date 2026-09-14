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
        Schema::create('merchant_level_business_rates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('level_id');
            $table->string('business_line', 16)->comment('recharge/card/movie/express');
            $table->decimal('rebate_rate', 6, 4);
            $table->datetimes();

            $table->unique(['level_id', 'business_line']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_level_business_rates');
    }
};
