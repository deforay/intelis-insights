<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSession extends Model
{
    protected $table = 'chat_sessions';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'user_id', 'title'];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'session_id');
    }
}
