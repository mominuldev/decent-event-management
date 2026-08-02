<?php

namespace App\Domain\CheckIn\Models;

use App\Domain\Shared\Support\HasUlid;
use Database\Factories\EventSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named admission session. A single continuous event holds one row here
 * and costs nothing; multi-session (gala, cultural night) needs no schema
 * change. See docs/README open question 1.
 */
class EventSession extends Model
{
    /** @use HasFactory<EventSessionFactory> */
    use HasFactory, HasUlid;

    protected $fillable = [
        'code',
        'name',
        'venue',
        'starts_at',
        'ends_at',
        'checkin_opens_at',
        'checkin_closes_at',
        'capacity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'checkin_opens_at' => 'datetime',
            'checkin_closes_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Gate, $this>
     */
    public function gates(): HasMany
    {
        return $this->hasMany(Gate::class);
    }

    /**
     * @return HasMany<CheckIn, $this>
     */
    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class);
    }

    public function isCheckInOpen(): bool
    {
        return now()->between($this->checkin_opens_at, $this->checkin_closes_at);
    }
}
