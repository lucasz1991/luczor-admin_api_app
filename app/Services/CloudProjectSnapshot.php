<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Portable content only: execution state, local paths and device settings never travel. */
class CloudProjectSnapshot
{
    public const MAX_BYTES = 5_242_880;

    public function validate(Request $request): array
    {
        if (strlen($request->getContent()) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['snapshot' => 'The cloud snapshot may not exceed 5 MiB.']);
        }

        // Preserve public message text exactly; global HTTP middleware trims ordinary form fields.
        $body = json_decode($request->getContent(), true, 16);
        $data = Validator::make(is_array($body) ? $body : [], [
            'expected_revision' => ['required', 'integer', 'min:0', 'max:9007199254740990'],
            'snapshot' => ['required', 'array:schema_version,project,messages,memories,summaries,conversations'],
            'snapshot.schema_version' => ['required', 'integer', 'in:1'],
            'snapshot.project' => ['required', 'array:name,goal,summary,goals,createdAt,updatedAt,archivedAt'],
            'snapshot.project.name' => ['required', 'string', 'max:255'],
            'snapshot.project.goal' => ['sometimes', 'nullable', 'string', 'max:60000'],
            'snapshot.project.summary' => ['present', 'nullable', 'string', 'max:200000'],
            'snapshot.project.goals' => ['present', 'array', 'max:500'],
            'snapshot.project.goals.*' => ['required', 'array:id,title,description,status,priority,createdAt,updatedAt,doneAt'],
            'snapshot.project.goals.*.id' => ['required', 'string', 'max:120', 'distinct:strict'],
            'snapshot.project.goals.*.title' => ['required', 'string', 'max:1000'],
            'snapshot.project.goals.*.description' => ['sometimes', 'nullable', 'string', 'max:60000'],
            'snapshot.project.goals.*.status' => ['required', 'in:open,in_progress,done'],
            'snapshot.project.goals.*.priority' => ['sometimes', 'in:low,normal,high'],
            'snapshot.project.goals.*.createdAt' => ['required', 'integer', 'min:0'],
            'snapshot.project.goals.*.updatedAt' => ['required', 'integer', 'min:0'],
            'snapshot.project.goals.*.doneAt' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'snapshot.project.createdAt' => ['required', 'integer', 'min:0'],
            'snapshot.project.updatedAt' => ['required', 'integer', 'min:0'],
            'snapshot.project.archivedAt' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'snapshot.messages' => ['present', 'array', 'max:2000'],
            'snapshot.messages.*' => ['required', 'array:id,role,content,ts,createdAt,visibility,conversationId'],
            'snapshot.messages.*.conversationId' => ['sometimes', 'string', 'max:190'],
            'snapshot.messages.*.id' => ['required', 'string', 'max:120', 'distinct:strict'],
            'snapshot.messages.*.role' => ['required', 'in:user,assistant'],
            'snapshot.messages.*.content' => ['present', 'nullable', 'string', 'max:200000'],
            'snapshot.messages.*.ts' => ['required', 'integer', 'min:0'],
            'snapshot.messages.*.createdAt' => ['required', 'integer', 'min:0'],
            'snapshot.messages.*.visibility' => ['required', 'in:visible'],
            'snapshot.conversations' => ['sometimes', 'array', 'max:2000'],
            'snapshot.conversations.*' => ['required', 'array:id,title,createdAt,updatedAt,archivedAt'],
            'snapshot.conversations.*.id' => ['required', 'string', 'max:190', 'distinct:strict'],
            'snapshot.conversations.*.title' => ['present', 'string', 'max:200'],
            'snapshot.conversations.*.createdAt' => ['required', 'integer', 'min:0'],
            'snapshot.conversations.*.updatedAt' => ['required', 'integer', 'min:0'],
            'snapshot.conversations.*.archivedAt' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'snapshot.memories' => ['present', 'array', 'max:500'],
            'snapshot.memories.*' => ['required', 'array:id,kind,key,value,priority,active,createdAt,updatedAt,source'],
            'snapshot.memories.*.id' => ['required', 'string', 'max:120', 'distinct:strict'],
            'snapshot.memories.*.kind' => ['required', 'in:rule,todo_policy,preference,fact,note'],
            'snapshot.memories.*.key' => ['required', 'string', 'max:1000'],
            'snapshot.memories.*.value' => ['required', 'string', 'max:60000'],
            'snapshot.memories.*.priority' => ['required', 'integer', 'between:1,5'],
            'snapshot.memories.*.active' => ['required', 'boolean'],
            'snapshot.memories.*.createdAt' => ['required', 'integer', 'min:0'],
            'snapshot.memories.*.updatedAt' => ['required', 'integer', 'min:0'],
            'snapshot.memories.*.source' => ['required', 'array:by'],
            'snapshot.memories.*.source.by' => ['required', 'in:user,assistant,system'],
            'snapshot.summaries' => ['present', 'array', 'max:500'],
            'snapshot.summaries.*' => ['required', 'array:id,text,createdAt'],
            'snapshot.summaries.*.id' => ['required', 'string', 'max:120', 'distinct:strict'],
            'snapshot.summaries.*.text' => ['required', 'string', 'max:200000'],
            'snapshot.summaries.*.createdAt' => ['required', 'integer', 'min:0'],
        ])->validate();

        // Nullable optional text is normalized for clients that always render a string.
        $data['snapshot']['project']['summary'] ??= '';
        foreach ($data['snapshot']['messages'] as &$message) {
            $message['content'] ??= '';
        }

        return $data;
    }
}
