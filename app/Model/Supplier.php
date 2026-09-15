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

namespace App\Model;

use Carbon\Carbon;

/**
 * 对应 `suppliers` 表（migrations/2026_09_14_091200_create_suppliers_table.php），
 * requirements.md 6.3「供应商信息」。
 *
 * `config` 是密文（`App\Crypto\Encryptor` 加密的整段 JSON 字符串），本 Model 不做
 * 加解密——加解密 + 脱敏是 Service 层职责（`App\Service\Admin\SupplierAdminService`），
 * 跟 `Merchant.app_secret`、`OrderRecharge.card_no`、`MerchantQualification.id_card_no`
 * 同样的约定，这里就是一个普通字符串属性。
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $business_line
 * @property string $driver
 * @property string $config
 * @property string $status
 * @property null|string $balance
 * @property null|Carbon $balance_synced_at
 * @property null|string $balance_warning_threshold
 * @property null|string $contact
 * @property null|string $settlement_info
 * @property null|string $remark
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Supplier extends Model
{
    protected ?string $table = 'suppliers';

    protected array $fillable = [
        'name',
        'code',
        'business_line',
        'driver',
        'config',
        'status',
        'balance',
        'balance_synced_at',
        'balance_warning_threshold',
        'contact',
        'settlement_info',
        'remark',
    ];
}
