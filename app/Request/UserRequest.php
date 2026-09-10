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

namespace App\Request;

use Hyperf\Validation\Request\FormRequest;

class UserRequest extends FormRequest
{
    /**
     * @var array<string, array<int, string>>
     */
    protected array $scenes = [
        'store' => ['name', 'email'],
        'update' => ['name', 'email'],
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $id = $this->route('id');
        $emailRule = 'required|email|max:128|unique:users,email';
        if ($id) {
            $emailRule .= ",{$id},id";
        }

        return [
            'name' => 'required|string|max:64',
            'email' => $emailRule,
        ];
    }
}
