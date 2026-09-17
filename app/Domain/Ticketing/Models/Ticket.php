<?php

namespace App\Domain\Ticketing\Models;

use App\Domain\CheckIn\Models\CheckIn;
use App\Domain\CheckIn\Models\EventSession;
use App\Domain\Notification\Listeners\QueueRegistrationConfirmedNotification;
use App\Domain\Notification\Mail\MailPresentation;
use App\Domain\Notification\Mail\ProvidesMailPresentation;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\HasStateMachine;
use App\Domain\Shared\Support\HasUlid;
use App\Domain\Ticketing\Actions\ResendTicketNotification;
use App\Domain\Ticketing\Services\TicketMailPresentation;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * An issued admission instrument. Immutable once created (ADR-09) —
 * corrections happen by void + reissue, never edit.
 */
class Ticket extends Model implements ProvidesMailPresentation
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory, HasStateMachine, HasUlid;

    public const array TRANSITIONS = [
        'issued' => ['active'],
        'active' => ['partially_admitted', 'fully_admitted', 'voided', 'refunded'],
        'partially_admitted' => ['fully_admitted', 'voided'],
        'fully_admitted' => [],
        'voided' => [],
        'refunded' => [],
    ];

    protected $fillable = [
        'ticket_number',
        'registration_id',
        'attendee_id',
        'ticket_type_id',
        'event_session_id',
        'status',
        'admits_total',
        'price_paid_paisa',
        'currency',
        'holder_name',
        'holder_name_bn',
        'holder_batch_year',
        'holder_type_label',
        'replaces_ticket_id',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'first_admitted_at' => 'datetime',
            'last_admitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Registration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /**
     * @return BelongsTo<Attendee, $this>
     */
    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }

    /**
     * @return BelongsTo<TicketType, $this>
     */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
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
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_ticket_id');
    }

    /**
     * @return HasOne<self, $this>
     */
    public function reissuedAs(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_ticket_id');
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function pdf(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'pdf_media_id');
    }

    /**
     * The "আমি থাকছি!" share card, rendered by `GenerateTicketAssetsJob` (or
     * on demand by the confirmation email, whichever runs first).
     *
     * @return BelongsTo<MediaFile, $this>
     */
    public function shareImage(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'share_image_media_id');
    }

    /**
     * @return HasOne<QrCode, $this>
     */
    public function qrCode(): HasOne
    {
        return $this->hasOne(QrCode::class);
    }

    /**
     * @return HasMany<CheckIn, $this>
     */
    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class);
    }

    /**
     * The QR plate and detail table this ticket's email is built around
     * (docs/06 §6.5 — the code is the admission instrument, so it belongs
     * in the message itself, not behind a link that expires).
     *
     * Resolution is delegated rather than inlined: the notification is
     * drained by a worker, and the model should not be the thing that
     * knows how to reach storage or render a symbol.
     */
    /**
     * A ticket is behind two emails, and the outbox row's template key is
     * the only thing that says which one is being sent: the ticket itself
     * (QR, number, gate details — sent by staff), or the
     * registration-confirmed message (the share card — sent at issuance).
     * Any other key gets the plain shell: better a message with no chrome
     * than a QR delivered by a template that was never meant to carry one.
     */
    public function mailPresentation(string $templateKey): ?MailPresentation
    {
        return match ($templateKey) {
            ResendTicketNotification::TEMPLATE_KEY => app(TicketMailPresentation::class)->for($this),
            QueueRegistrationConfirmedNotification::TEMPLATE_KEY => app(TicketMailPresentation::class)->registrationConfirmed($this),
            default => null,
        };
    }

    /**
     * Atomic conditional admission (ADR-04). Zero affected rows means
     * duplicate or over-admission — the caller must reject, never retry
     * with a plain UPDATE. Bypasses {@see HasStateMachine::transitionTo()}
     * because the status change must happen inside the same conditional
     * UPDATE as the counter increment, not a separate fill+save.
     */
    public function tryAdmit(int $partySize): bool
    {
        $now = now();

        // MySQL evaluates SET assignments left to right within one UPDATE,
        // so `admitted_count` in the CASE below already reflects the
        // post-increment value assigned just above — it must not add
        // $partySize again.
        $affected = DB::update(
            "UPDATE tickets
                SET admitted_count = admitted_count + ?,
                    status = CASE WHEN admitted_count >= admits_total THEN 'fully_admitted' ELSE 'partially_admitted' END,
                    first_admitted_at = COALESCE(first_admitted_at, ?),
                    last_admitted_at = ?,
                    manifest_version = manifest_version + 1,
                    updated_at = ?
              WHERE id = ? AND admitted_count + ? <= admits_total",
            [$partySize, $now, $now, $now, $this->id, $partySize]
        );

        return $affected > 0;
    }
}
