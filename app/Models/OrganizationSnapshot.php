<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationSnapshot extends Model
{
    protected $fillable = ['organization_id', 'rating', 'ratings_count', 'reviews_count', 'payload'];

    protected function casts(): array
    {
        return [
            'rating' => 'decimal:2',
            'payload' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
