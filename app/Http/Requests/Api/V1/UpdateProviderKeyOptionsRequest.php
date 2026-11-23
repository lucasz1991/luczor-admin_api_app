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
            'provider_fallback' => ['required', 'boolean'],
        ];
    }
}
