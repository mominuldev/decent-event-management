<?php

namespace App\Domain\Registration\Models;

use App\Domain\Shared\Support\HasUlid;
use Database\Factories\RegistrationGuestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A family/couple member on a registration. T-shirt size belongs to the
 * person, not the registration (ADR-10).
 */
class RegistrationGuest extends Model
{
    /** @use HasFactory<RegistrationGuestFactory> */
    use HasFactory, HasUlid;

    public $timestamps = true;

    protected $fillable = [
        'registration_id',
        'full_name',
        'relation',
        'age_group',
        'age',
        'gender',
        'tshirt_required',
        'tshirt_size',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'tshirt_required' => 'boolean',
            'is_admitted' => 'boolean',
            'admitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Registration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
