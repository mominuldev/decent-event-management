<?php

namespace App\Domain\CheckIn\Models;

use App\Domain\Shared\Support\HasUlid;
use Database\Factories\GateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gate extends Model
{
    /** @use HasFactory<GateFactory> */
    use HasFactory, HasUlid;

    protected $fillable = [
        'code',
        'name',
        'event_session_id',
        'allowed_ticket_type_ids',
        'location_note',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allowed_ticket_type_ids' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<EventSession, $this>
     */
    public function eventSession(): BelongsTo
    {
        return $this->belongsTo(EventSession::class);
    }

    /**
     * @return HasMany<VolunteerGateAssignment, $this>
     */
    public function volunteerAssignments(): HasMany
    {
        return $this->hasMany(VolunteerGateAssignment::class);
    }

    /**
     * @return HasMany<CheckIn, $this>
     */
    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class);
    }

    public function allowsTicketType(int $ticketTypeId): bool
    {
        return $this->allowed_ticket_type_ids === null
            || in_array($ticketTypeId, $this->allowed_ticket_type_ids, true);
    }
}
