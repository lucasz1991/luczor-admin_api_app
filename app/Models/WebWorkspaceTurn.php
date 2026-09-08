<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebWorkspaceTurn extends Model
{
    protected $fillable = ['web_workspace_chat_id', 'submission_id', 'device_job_id', 'prompt'];

    protected $casts = ['prompt' => 'encrypted'];

    /** @return BelongsTo<DeviceJob, $this> */
    public function deviceJob(): BelongsTo
    {
        return $this->belongsTo(DeviceJob::class);
    }
}
