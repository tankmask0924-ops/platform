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
        Schema::create('merchant_qualifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('merchant_id');
            $table->string('type', 16)->comment('company / individual，提交时的类型');
            $table->string('company_name', 128)->nullable();
            $table->string('business_license_no', 64)->nullable();
            $table->string('business_license_image', 255)->nullable();
            $table->string('legal_person_name', 64)->nullable();
            $table->string('contact_name', 64)->nullable();
            $table->string('contact_phone', 20);
            $table->string('id_card_name', 64)->nullable();
            $table->string('id_card_no', 32)->nullable()->comment('加密存储');
            $table->json('id_card_images')->nullable();
            $table->string('status', 16)->default('pending')->comment('pending/approved/rejected');
            $table->string('reject_reason', 255)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->datetimes();

            $table->index(['merchant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_qualifications');
    }
};
