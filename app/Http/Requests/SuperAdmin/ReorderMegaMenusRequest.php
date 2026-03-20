<?php

namespace App\Http\Requests\SuperAdmin;

use App\Support\PlatformPermissions;
use Illuminate\Foundation\Http\FormRequest;

class ReorderMegaMenusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) $user && ($user->isSuperadmin() || $user->hasPlatformPermission(PlatformPermissions::MEGA_MENUS_MANAGE));
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:mega_menus,id'],
        ];
    }
}
