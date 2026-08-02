<?php

namespace App\Domain\CheckIn\Models;

use App\Domain\Shared\Models\User;
use Database\Factories\VolunteerGateAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VolunteerGateAssignment extends Model
{
    /** @use HasFactory<VolunteerGateAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'volunteer_profile_id',
        'gate_id',
        'event_session_id',
        'assigned_by_user_id',
    ];

    /**
     * @return BelongsTo<VolunteerProfile, $this>
     */
    public function volunteerProfile(): BelongsTo
    {
        return $this->belongsTo(VolunteerProfile::class);
    }

    /**
     * @return BelongsTo<Gate, $this>
     */
    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class);
    }

    /**
     * @return BelongsTo<EventSession, $this>
     */
    public function eventSession(): BelongsTo
    {
        return $this->belongsTo(EventSession::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
