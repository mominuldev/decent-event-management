<?php

namespace App\Domain\Ticketing\Models;

use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\TicketTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Sellable ticket category. Sold/reserved counters are updated with a
 * race-free conditional UPDATE — see {@see self::tryReserve()}.
 */
class TicketType extends Model
{
    /** @use HasFactory<TicketTypeFactory> */
    use HasFactory, HasUlid, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'name_bn',
        'description',
        'base_price_paisa',
        'additional_adult_price_paisa',
        'additional_child_price_paisa',
        'current_student_price_paisa',
        'currency',
        'base_admits',
        'max_admits',
        'child_free_under_age',
        'allowed_participant_types',
        'quantity_total',
        'quantity_reserved',
        'quantity_sold',
        'requires_approval',
        'includes_tshirt',
        'includes_meal',
        'sale_starts_at',
        'sale_ends_at',
        'is_active',
        'is_public',
        'badge_color',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'allowed_participant_types' => 'array',
            'requires_approval' => 'boolean',
            'includes_tshirt' => 'boolean',
            'includes_meal' => 'boolean',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'sale_starts_at' => 'datetime',
            'sale_ends_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Registration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * What the registrant's own seat costs, given who they are.
     *
     * The single definition of which of this type's price columns applies
     * to a buyer — `CreateRegistration` asks this rather than reading
     * `base_price_paisa` directly, so an admin-created or imported
     * registration cannot quietly bill a different rate than the public
     * checkout does.
     *
     * Only the registrant's seat moves. Family they bring is priced at
     * `additional_adult_price_paisa`/`additional_child_price_paisa`
     * whoever the registrant is: the student discount follows the student,
     * not their whole party.
     *
     * A NULL `current_student_price_paisa` means this type has no student
     * rate, so everyone pays the base price. Zero is a real price — a free
     * student ticket — and is deliberately *not* treated as "unset".
     */
    public function basePriceFor(?string $participantType): int
    {
        if ($participantType === 'current_student' && $this->current_student_price_paisa !== null) {
            return (int) $this->current_student_price_paisa;
        }

        return (int) $this->base_price_paisa;
    }

    /**
     * Atomically reserves one unit of capacity. Zero affected rows means
     * sold out — see docs/03 §3.7.
     */
    public function tryReserve(int $quantity = 1): bool
    {
        $affected = DB::table('ticket_types')
            ->where('id', $this->id)
            ->where(function ($query) use ($quantity): void {
                $query->whereNull('quantity_total')
                    ->orWhereRaw('quantity_sold + quantity_reserved + ? <= quantity_total', [$quantity]);
            })
            ->increment('quantity_reserved', $quantity);

        return $affected > 0;
    }

    /**
     * Atomically converts reserved capacity into a sale on payment
     * success (docs/05 §5.3) — never a plain `save()`, since a concurrent
     * reservation/release must not be lost between read and write.
     */
    public function confirmSale(int $quantity = 1): bool
    {
        $affected = DB::update(
            'UPDATE ticket_types SET quantity_reserved = quantity_reserved - ?, quantity_sold = quantity_sold + ? WHERE id = ? AND quantity_reserved >= ?',
            [$quantity, $quantity, $this->id, $quantity]
        );

        return $affected > 0;
    }

    /**
     * Atomically releases reserved capacity without a sale — payment
     * failed, expired, or was cancelled before completion.
     */
    public function releaseReservation(int $quantity = 1): bool
    {
        $affected = DB::table('ticket_types')
            ->where('id', $this->id)
            ->where('quantity_reserved', '>=', $quantity)
            ->decrement('quantity_reserved', $quantity);

        return $affected > 0;
    }
}
