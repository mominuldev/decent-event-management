<?php

namespace App\Domain\Registration\Support;

use App\Domain\Payment\Models\Payment;
use App\Domain\Shared\Support\EventSettingCatalogue;
use App\Domain\Ticketing\Models\TicketType;

/**
 * How many people one registration may admit, the registrant included.
 *
 * Two knobs, one question each. The ticket type's `allows_family` switch
 * says *whether* family may be added on it; the event-wide
 * `registration.max_family_size` setting says *how many* — the organiser's
 * one rule for the whole event, changed from the settings console without
 * a deploy. A ticket with the switch off admits exactly one. `max_admits`
 * deliberately plays no part (2026-09-15): it did at first, and an
 * organiser who ticked the switch on a ticket left at the default
 * `max_admits` of 1 got nothing for it, which is not what a checkbox
 * labelled "Family members allowed" should do.
 *
 * Nothing enforced any party size before this class existed (CLAUDE.md
 * D7): `StoreRegistrationRequest` capped `adults_count` and
 * `children_count` at 10 *each*, so a party of twenty on a one-seat ticket
 * was accepted, and the public form's "up to N members" was a promise only
 * the browser kept.
 *
 * Read at each use, never cached, for the same reason
 * {@see Payment::intentTtlMinutes()} is not: the
 * organiser can tighten it mid-sale and the next registration must see it.
 */
final class PartySize
{
    public const SETTING_KEY = 'registration.max_family_size';

    /**
     * The event-wide ceiling, registrant included. Never below one: a
     * registration always admits the registrant, so a setting edited to 0
     * would otherwise refuse every registration at once.
     */
    public static function eventMax(): int
    {
        $setting = EventSettingCatalogue::resolve(self::SETTING_KEY);
        $value = $setting?->typedValue();

        return is_int($value) ? max(1, $value) : 1;
    }

    /** The ceiling this ticket type sells under: the event's rule, or one. */
    public static function limitFor(TicketType $ticketType): int
    {
        return $ticketType->allows_family ? self::eventMax() : 1;
    }

    /** Members addable beside the registrant on this ticket type. */
    public static function maxMembersFor(TicketType $ticketType): int
    {
        return self::limitFor($ticketType) - 1;
    }
}
