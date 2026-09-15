<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'yandex_url',
        'yandex_business_id',
        'name',
        'rating',
        'ratings_count',
        'reviews_count',
        'status',
        'parse_progress',
        'last_error',
        'parsed_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'decimal:2',
            'parsed_at' => 'datetime',
        ];
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(OrganizationSnapshot::class);
    }
}
