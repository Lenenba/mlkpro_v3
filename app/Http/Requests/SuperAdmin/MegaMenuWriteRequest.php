<?php

namespace App\Http\Requests\SuperAdmin;

use App\Models\MegaMenu;
use App\Services\MegaMenus\MegaMenuPayloadSanitizer;
use App\Support\MegaMenuOptions;
use App\Support\PlatformPermissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

abstract class MegaMenuWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) $user && ($user->isSuperadmin() || $user->hasPlatformPermission(PlatformPermissions::MEGA_MENUS_MANAGE));
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('slug')) {
            $this->merge([
                'slug' => Str::slug((string) $this->input('slug')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function sanitized(): array
    {
        return app(MegaMenuPayloadSanitizer::class)->sanitize($this->validated());
    }

    protected function commonRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:160', $this->slugRule()],
            'status' => ['required', Rule::in(MegaMenuOptions::statuses())],
            'display_location' => ['required', Rule::in(MegaMenuOptions::displayLocations())],
            'custom_zone' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'css_classes' => ['nullable', 'string', 'max:255'],
            'ordering' => ['nullable', 'integer', 'min:0'],
            'settings' => ['nullable', 'array'],
            'items' => ['required', 'array', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            app(MegaMenuPayloadSanitizer::class)->validateStructure($this->all(), $validator);
        });
    }

    abstract protected function slugRule(): Unique;

    protected function currentMenu(): ?MegaMenu
    {
        $menu = $this->route('megaMenu');

        return $menu instanceof MegaMenu ? $menu : null;
    }
}
