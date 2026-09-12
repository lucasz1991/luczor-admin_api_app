<?php

namespace App\Models;

use App\Casts\ConversationArchiveData;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array|null $payload
 * @property int|null $conversation_sequence
 * @property string|null $content_hash
 */
class LuczorMessageArchive extends Model
{
    protected $fillable = ['user_id', 'client_id', 'project_ref_id', 'entity_type', 'external_id', 'payload', 'created_at_client', 'updated_at_client', 'conversation_ref_id', 'conversation_sequence', 'content_hash'];

    protected $casts = ['payload' => ConversationArchiveData::class, 'created_at_client' => 'datetime', 'updated_at_client' => 'datetime', 'conversation_sequence' => 'integer'];
}
