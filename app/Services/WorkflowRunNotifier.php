<?php

namespace App\Services;

use App\Models\WorkflowRun;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkflowRunNotifier
{
    public function notifyTerminal(WorkflowRun $run): void
    {
        if (! in_array($run->status, ['completed', 'failed', 'cancelled'], true)
            || $run->parent_workflow_run_id) {
            return;
        }

        $title = match ($run->status) {
            'completed' => 'Workflow abgeschlossen',
            'failed' => 'Workflow fehlgeschlagen',
            default => 'Workflow abgebrochen',
        };
        $body = $run->definition?->name
            ? '„'.$run->definition->name.'“ ist '.$this->statusLabel($run->status).'.'
            : 'Der Workflow ist '.$this->statusLabel($run->status).'.';

        try {
            app(AppNotificationService::class)->send(
                user: (int) $run->user_id,
                notificationId: 'workflow-run:'.$run->public_id,
                title: $title,
                body: $body,
                category: 'workflow',
                data: [
                    'workflow_run_id' => $run->public_id,
                    'workflow_definition_id' => $run->workflow_definition_id,
                    'status' => $run->status,
                ],
                priority: $run->status === 'failed' ? 'high' : 'normal',
            );
        } catch (Throwable $exception) {
            Log::notice('Luczor workflow notification could not be persisted or queued.', [
                'notification_id' => 'workflow-run:'.$run->public_id,
                'user_id' => $run->user_id,
                'error_class' => $exception::class,
            ]);
        }
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'abgeschlossen',
            'failed' => 'fehlgeschlagen',
            default => 'abgebrochen',
        };
    }
}
