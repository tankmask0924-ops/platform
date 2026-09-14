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

namespace App\Dao;

use App\Model\MerchantQualification;

class MerchantQualificationDao extends AbstractDao
{
    protected string $model = MerchantQualification::class;

    public function findByMerchantId(int $merchantId): ?MerchantQualification
    {
        return $this->newQuery()->where('merchant_id', $merchantId)->first();
    }
}
