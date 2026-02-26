<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $table = 'chat_messages';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'session_id', 'role', 'content',
        'plan_json', 'query_result_json', 'chart_json',
    ];

    protected $casts = [
        'plan_json' => 'array',
        'query_result_json' => 'array',
        'chart_json' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }
}
