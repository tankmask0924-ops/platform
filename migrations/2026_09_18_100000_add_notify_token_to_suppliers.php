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
use Hyperf\DbConnection\Db;

/*
 * requirements.md 6.3「回调地址平台自动生成，形如 /notify/{供应商编码}，带随机令牌」：
 * 供应商回调不做来源 IP 白名单，令牌挡住随便猜编码就来打回调的请求。
 * 新建供应商由 App\Model\Supplier::creating() 生成，这里给已有的供应商补上。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('notify_token', 64)->default('')->after('code')->comment('回调地址里的随机令牌');
        });

        foreach (Db::table('suppliers')->where('notify_token', '')->pluck('id') as $id) {
            Db::table('suppliers')->where('id', $id)->update(['notify_token' => bin2hex(random_bytes(16))]);
        }
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('notify_token');
        });
    }
};
