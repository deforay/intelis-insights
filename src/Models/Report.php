<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $table = 'reports';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'user_id', 'title',
        'plan_json', 'chart_json',
        'access_scope', 'pinned',
    ];

    protected $casts = [
        'plan_json' => 'array',
        'chart_json' => 'array',
        'pinned' => 'boolean',
    ];
}
