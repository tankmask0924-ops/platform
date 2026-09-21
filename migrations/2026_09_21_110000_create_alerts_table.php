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
        Schema::create('alerts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('type', 32)->comment('见 App\Model\Alert::TYPES');
            $table->string('level', 16)->comment('warning / critical');
            $table->string('related_type', 32)->nullable()->comment('supplier/product/merchant/order，为空表示全局告警');
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('message', 255)->comment('告警内容，含关键数值');
            $table->string('status', 16)->default('open')->comment('open 未处理 / resolved 已处理 / ignored 已忽略');
            $table->unsignedInteger('occurrence_count')->default(1)->comment('同一条告警重复触发的次数');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('triggered_at')->comment('最近一次触发时间');
            $table->datetimes();

            $table->index(['status', 'triggered_at']);
            $table->index(['type', 'related_type', 'related_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
