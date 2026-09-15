<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $fillable = [
        'organization_id',
        'external_id',
        'author_name',
        'reviewed_at',
        'text',
        'rating',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
