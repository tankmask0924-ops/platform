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

/**
 * order_recharges 没有自增 id，主键就是 order_id（一对一挂在 orders 上），
 * 所以要关掉 Eloquent 默认的自增主键行为，否则 save()/新增会去读一个不存在的
 * 自增 id 导致出错。card_no/card_pwd 是密文（App\Crypto\Encryptor 加密），
 * 这里不做解密，解密是 Service 层（App\Service\OpenApi\OrderQueryService）的职责，
 * 跟 Merchant 不在 Model 里解密 app_secret 是同一个约定。
 *
 * @property int $order_id
 * @property int $product_id
 * @property null|string $recharge_account
 * @property null|string $card_no
 * @property null|string $card_pwd
 * @property string $rebate_amount
 */
class OrderRecharge extends Model
{
    public bool $incrementing = false;

    /**
     * 没有 created_at/updated_at 列（见迁移文件），关掉时间戳自动维护。
     */
    public bool $timestamps = false;

    protected ?string $table = 'order_recharges';

    protected string $primaryKey = 'order_id';

    protected array $fillable = [
        'order_id',
        'product_id',
        'recharge_account',
        'card_no',
        'card_pwd',
        'rebate_amount',
    ];
}
