<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreMegaMenuRequest extends MegaMenuWriteRequest
{
    public function rules(): array
    {
        return $this->commonRules();
    }

    protected function slugRule(): Unique
    {
        return Rule::unique('mega_menus', 'slug');
    }
}
