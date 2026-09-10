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

namespace App\Service;

use App\Dao\UserDao;
use App\Job\SendUserWelcomeJob;
use App\Model\User;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Database\Model\Collection;
use Hyperf\Di\Annotation\Inject;

class UserService extends AbstractService
{
    #[Inject]
    protected UserDao $userDao;

    #[Inject]
    protected DriverFactory $driverFactory;

    public function list(int $page, int $perPage): Collection
    {
        return $this->userDao->paginate($page, $perPage);
    }

    public function find(int $id): ?User
    {
        return $this->userDao->find($id);
    }

    public function create(array $attributes): User
    {
        $user = $this->userDao->create($attributes);

        $this->driverFactory->get('default')->push(new SendUserWelcomeJob($user->id, $user->email));

        return $user;
    }

    public function update(int $id, array $attributes): bool
    {
        return $this->userDao->update($id, $attributes);
    }

    public function delete(int $id): bool
    {
        return $this->userDao->delete($id);
    }
}
