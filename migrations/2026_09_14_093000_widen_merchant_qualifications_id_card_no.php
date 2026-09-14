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
use Hyperf\DbConnection\Db;

/*
 * merchant_qualifications.id_card_no 原先建表时是 varchar(32)（够放一个明文 18 位身份证号，
 * 但字段注释已经写了「加密存储」）。App\Crypto\Encryptor 用 AES-256-GCM，密文是
 * base64(12 字节 nonce + 明文等长密文 + 16 字节 tag)，一个 18 位身份证号加密后
 * 单是 nonce+tag 的 28 字节固定开销就已经 base64 到 40 字节左右，32 字符的列完全放不下
 * 任何加密结果（哪怕明文是空字符串）——实际测试中一插入就报
 * 「Data too long for column 'id_card_no'」，注册功能会 100% 失败。
 *
 * 这是原表定义的一个遗留 bug：其它同样用 Encryptor 加密存储的列
 * （merchants.app_secret、order_recharges.card_no/card_pwd）建表时都用的是 varchar(255)，
 * 这里改成同样的宽度对齐，不是新加需求。
 */
/*
 * 用原生 SQL 而不是 Blueprint::change()：后者依赖 doctrine/dbal（项目没装，
 * 只在 composer.lock 里作为其它包的 suggest 出现），装一个新的重量级依赖
 * 只为改一个列宽不划算，直接写 MODIFY 更直接。
 */
return new class extends Migration {
    public function up(): void
    {
        Db::statement(
            "ALTER TABLE `merchant_qualifications` MODIFY `id_card_no` VARCHAR(255) NULL COMMENT '加密存储'"
        );
    }

    public function down(): void
    {
        Db::statement(
            "ALTER TABLE `merchant_qualifications` MODIFY `id_card_no` VARCHAR(32) NULL COMMENT '加密存储'"
        );
    }
};
