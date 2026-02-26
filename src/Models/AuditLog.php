<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_log';
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'action', 'metric',
        'filters_json', 'request_id',
    ];

    protected $casts = [
        'filters_json' => 'array',
        'created_at' => 'datetime',
    ];
}
