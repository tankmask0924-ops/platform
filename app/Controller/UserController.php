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

namespace App\Controller;

use App\Request\UserRequest;
use App\Service\UserService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;
use Hyperf\Validation\Annotation\Scene;

#[Controller(prefix: '/users')]
class UserController extends AbstractController
{
    #[Inject]
    protected UserService $userService;

    #[GetMapping(path: '')]
    public function index(): array
    {
        $page = (int) $this->request->input('page', 1);
        $perPage = (int) $this->request->input('per_page', 15);

        return $this->userService->list($page, $perPage)->toArray();
    }

    #[GetMapping(path: '{id}')]
    public function show(int $id): array
    {
        $user = $this->userService->find($id);

        if (! $user) {
            return ['error' => 'User not found'];
        }

        return $user->toArray();
    }

    #[Scene(scene: 'store')]
    #[PostMapping(path: '')]
    public function store(UserRequest $request): array
    {
        return $this->userService->create($request->validated())->toArray();
    }

    #[Scene(scene: 'update')]
    #[PutMapping(path: '{id}')]
    public function update(int $id, UserRequest $request): array
    {
        return ['success' => $this->userService->update($id, $request->validated())];
    }

    #[DeleteMapping(path: '{id}')]
    public function destroy(int $id): array
    {
        return ['success' => $this->userService->delete($id)];
    }
}
