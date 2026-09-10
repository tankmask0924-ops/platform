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

namespace App\Job;

use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

class SendUserWelcomeJob extends Job
{
    public function __construct(protected int $userId, protected string $email)
    {
    }

    public function handle(): void
    {
        $this->logger()->info(sprintf('Send welcome email to user #%d (%s)', $this->userId, $this->email));
    }

    protected function logger(): LoggerInterface
    {
        return ApplicationContext::getContainer()
            ->get(LoggerFactory::class)
            ->get('async-queue');
    }
}
