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

namespace App\Storage;

/**
 * 按配置建云存储客户端。单独一个类是为了测试时能在容器里换掉它，不真的连云存储。
 */
class CloudStorageClientFactory
{
    public function make(StorageConfig $config): CloudStorageClient
    {
        return new CloudStorageClient($config);
    }
}
