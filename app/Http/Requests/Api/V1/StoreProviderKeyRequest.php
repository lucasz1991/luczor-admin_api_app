<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreProviderKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        return [
            // Printable, whitespace-free secret; never echoed back.
            'api_key' => ['required', 'string', 'min:8', 'max:512', 'regex:/^[\x21-\x7E]+$/'],
        ];
    }
}
