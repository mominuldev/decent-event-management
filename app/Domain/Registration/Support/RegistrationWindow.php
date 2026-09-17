<?php

namespace App\Domain\Registration\Support;

use App\Domain\Shared\Support\EventSettingCatalogue;
use App\Domain\Ticketing\Models\TicketType;
use Carbon\CarbonInterface;

/**
 * When a registration on a ticket type is accepted, the registrant's side.
 *
 * Two knobs again, one question each, as with {@see PartySize}. The
 * event-wide `registration.opens_at` / `registration.closes_at` settings
 * are the organiser's one window for the whole event — "Registration
 * opens", set from the settings console and expected to hold to the
 * minute. A type's own `sale_starts_at` / `sale_ends_at` can only narrow
 * it: an early-bird type that closes sooner, a late type that opens later.
 * So the moment a type is buyable from is the *later* of the two starts,
 * and the moment it stops is the *earlier* of the two ends; a NULL bound
 * on either side is no bound.
 *
 * Nothing read `registration.opens_at` before 2026-09-17 (CLAUDE.md D7):
 * the setting was catalogued with a label promising "before this moment
 * the public registration form is closed", the organiser set it for five
 * o'clock, and the form stayed open all afternoon.
 *
 * Read at each use, never cached, for the same reason {@see PartySize} is
 * not: the organiser can move the opening from the console and the next
 * request must see it.
 */
final class RegistrationWindow
{
    public const OPENS_AT_KEY = 'registration.opens_at';

    public const CLOSES_AT_KEY = 'registration.closes_at';

    /** The event-wide opening, or null when the setting is unset. */
    public static function opensAt(): ?CarbonInterface
    {
        return self::setting(self::OPENS_AT_KEY);
    }

    /** The event-wide close, or null when the setting is unset. */
    public static function closesAt(): ?CarbonInterface
    {
        return self::setting(self::CLOSES_AT_KEY);
    }

    /**
     * When registration on this type opens: the later of the event's
     * opening and the type's own `sale_starts_at`. Null means it has no
     * opening to wait for.
     */
    public static function opensFor(TicketType $ticketType): ?CarbonInterface
    {
        return self::later(self::opensAt(), $ticketType->sale_starts_at);
    }

    /**
     * When registration on this type closes: the earlier of the event's
     * close and the type's own `sale_ends_at`. Null means it never does.
     */
    public static function closesFor(TicketType $ticketType): ?CarbonInterface
    {
        return self::earlier(self::closesAt(), $ticketType->sale_ends_at);
    }

    /** Whether a registration on this type is accepted right now. */
    public static function isOpenFor(TicketType $ticketType): bool
    {
        $now = now();
        $opensAt = self::opensFor($ticketType);
        $closesAt = self::closesFor($ticketType);

        if ($opensAt !== null && $now->lt($opensAt)) {
            return false;
        }

        if ($closesAt !== null && $now->gt($closesAt)) {
            return false;
        }

        return true;
    }

    private static function setting(string $key): ?CarbonInterface
    {
        $value = EventSettingCatalogue::resolve($key)?->typedValue();

        return $value instanceof CarbonInterface ? $value : null;
    }

    private static function later(?CarbonInterface $a, ?CarbonInterface $b): ?CarbonInterface
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->gt($b) ? $a : $b;
    }

    private static function earlier(?CarbonInterface $a, ?CarbonInterface $b): ?CarbonInterface
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->lt($b) ? $a : $b;
    }
}
