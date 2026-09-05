<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportBufferChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'channel_id' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9_-]+\z/'],
        ];
    }
}
