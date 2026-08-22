<?php

namespace App\Domain\Notification\Gateways;

/**
 * Collapses the SMPP delivery-receipt vocabulary REVE speaks into the
 * three outcomes this system acts on.
 *
 * The wire values are SMPP v3.4 §5.2.28 `message_state` names, which is
 * what a Bangladeshi carrier's DLR carries end to end — REVE passes them
 * through rather than inventing its own. The split that matters is
 * *pending vs terminal*: `ACCEPTD` means the carrier took the message and
 * nothing more, so treating it as delivered would report a message as
 * having reached a handset that may still be queued behind a switched-off
 * phone for 24 hours.
 */
final class ReveSmsDeliveryState
{
    public const string DELIVERED = 'delivered';

    public const string FAILED = 'failed';

    public const string PENDING = 'pending';

    /** @var array<int, string> */
    private const array DELIVERED_CODES = ['DELIVRD', 'DELIVERED', 'DELIVER', 'SUCCESS'];

    /** @var array<int, string> */
    private const array FAILED_CODES = [
        'UNDELIV', 'UNDELIVERABLE', 'REJECTD', 'REJECTED', 'EXPIRED', 'DELETED',
        'FAILED', 'ERROR', 'INVALID', 'BLOCKED', 'UNKNOWN_SUBSCRIBER',
    ];

    /** @var array<int, string> */
    private const array PENDING_CODES = [
        'ACCEPTD', 'ACCEPTED', 'ENROUTE', 'PENDING', 'WAITING', 'SUBMITTED',
        'SENT', 'QUEUED', 'UNKNOWN',
    ];

    public static function fromProviderStatus(?string $status): string
    {
        $normalised = strtoupper(trim((string) $status));

        if ($normalised === '') {
            return self::PENDING;
        }

        // A receipt often arrives as `stat:DELIVRD err:000` or
        // `id:123 stat:UNDELIV`, not as a bare word.
        if (preg_match('/\bstat[:=]\s*([A-Z_]+)/i', $normalised, $matches) === 1) {
            $normalised = strtoupper($matches[1]);
        }

        if (in_array($normalised, self::DELIVERED_CODES, true)) {
            return self::DELIVERED;
        }

        if (in_array($normalised, self::FAILED_CODES, true)) {
            return self::FAILED;
        }

        if (in_array($normalised, self::PENDING_CODES, true)) {
            return self::PENDING;
        }

        // An unrecognised receipt is left pending rather than guessed
        // either way: reporting a delivery that did not happen and
        // reporting a failure that did not happen are both worse than
        // reporting nothing yet, and the raw value is kept on the event row
        // for whoever adds the mapping.
        return self::PENDING;
    }
}
