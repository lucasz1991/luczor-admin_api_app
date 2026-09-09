<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class WorkflowVisionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        abort_unless($this->isJson(), 415, 'workflow_vision_json_required');
        abort_if(strlen($this->getContent()) > 3 * 1024 * 1024, 413, 'workflow_vision_request_too_large');
        $body = json_decode($this->getContent(), true, 32);
        abort_unless(is_array($body) && ! array_is_list($body), 422, 'workflow_vision_json_invalid');
        // Preserve approval-bound strings exactly; global TrimStrings must not alter signed input.
        $this->replace($body);
    }

    public function authorize(): bool
    {
        abort_unless($this->isJson(), 415, 'workflow_vision_json_required');
        abort_if(strlen($this->getContent()) > 3 * 1024 * 1024, 413, 'workflow_vision_request_too_large');

        return true;
    }

    public function rules(): array
    {
        return [
            'schema_version' => ['required', 'integer', 'in:1'],
            'client_id' => ['required', 'string', 'max:120'], 'project_id' => ['required', 'string', 'max:120'],
            'workflow_id' => ['required', 'uuid'], 'workflow_execution_id' => ['required', 'uuid'],
            'instruction' => ['required', 'string', 'min:1', 'max:12000'],
            'output_format' => ['required', 'in:text,json'], 'max_output_chars' => ['required', 'integer', 'between:256,20000'],
            'policy_revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'image' => ['required', 'array:artifact_id,mime,bytes,sha256,width,height,base64'],
            'image.artifact_id' => ['required', 'uuid'], 'image.mime' => ['required', 'in:image/png'],
            'image.bytes' => ['required', 'integer', 'between:1,2097152'], 'image.sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'image.width' => ['required', 'integer', 'between:1,16384'], 'image.height' => ['required', 'integer', 'between:1,16384'],
            'image.base64' => ['required', 'string', 'max:2796204'],
            'approval' => ['required', 'array:hash,token,expires_at'],
            'approval.hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'approval.token' => ['required', 'uuid'],
            'approval.expires_at' => ['required', 'string', 'date', 'max:40', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = ['schema_version', 'client_id', 'project_id', 'workflow_id', 'workflow_execution_id', 'instruction', 'output_format', 'max_output_chars', 'policy_revision', 'image', 'approval'];
            if (array_diff(array_keys($this->json()->all()), $allowed) !== []) {
                $validator->errors()->add('request', 'workflow_vision_unknown_field');
            }
            foreach (['schema_version', 'max_output_chars', 'image.bytes', 'image.width', 'image.height'] as $field) {
                if (! is_int($this->input($field))) {
                    $validator->errors()->add($field, 'workflow_vision_integer_required');
                }
            }
        });
    }
}
