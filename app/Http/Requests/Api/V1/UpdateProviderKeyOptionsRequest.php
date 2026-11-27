<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProviderKeyOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        return [
            // Either option may be sent alone; both are stored per account.
            // `preferred_model: null` clears the stored choice.
            'provider_fallback' => ['sometimes', 'boolean'],
            'preferred_model' => ['sometimes', 'nullable', 'string', 'max:160', 'regex:/^[a-zA-Z0-9._-]+$/'],
        ];
    }
}
