<?php

namespace App\Http\Controllers\Api\Attendee;

use App\Domain\Notification\Actions\QueueNotification;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Support\AttendeeIdentity;
use App\Domain\Shared\Support\PasswordHash;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OAT;

/**
 * Magic link (email) or OTP (SMS) — attendees never have a password.
 * See docs/02 §2.2 "Attendee".
 */
#[OAT\Tag(name: 'Authentication')]
class AuthController extends Controller
{
    private const int TOKEN_TTL_MINUTES = 15;

    private const int SESSION_DAYS = 30;

    /** Guesses allowed against one six-digit code before it is burned. */
    private const int MAX_CODE_ATTEMPTS = 5;

    /**
     * Said whether or not the identifier is registered, so the response
     * carries no enumeration signal.
     *
     * It used to promise a "login link", left over from the flow this
     * replaced. A code is not a link, and the frontend spent a support
     * cycle on someone waiting for an email that was never going to arrive.
     */
    /**
     * Granted only to a token minted by `verify()`, and burned the first
     * time it is spent. A 30-day session must not stay able to change the
     * password weeks after the code that justified it.
     */
    private const string PASSWORD_RESET_ABILITY = 'password-reset';

    private const string CODE_SENT_MESSAGE = 'If that account exists, a 6-digit sign-in code has been sent by SMS.';

    /**
     * A real bcrypt hash of a value nobody knows, used only so a sign-in
     * attempt for an unknown number does the same work as one for a known
     * number. Comparing against nothing returns immediately, and that
     * difference is measurable.
     */
    private const string TIMING_DUMMY_HASH = '$2y$12$YcqxnxT79iUGlALWk59kAuPqJbPZJyfstYh/79Q03EWvThQKeoxPi';

    #[OAT\Post(
        path: '/attendee/auth/request-link',
        summary: 'Request a login link for an attendee, sent by SMS to their registered mobile number',
        tags: ['Authentication'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(
                            property: 'mobile',
                            type: 'string',
                            required: ['mobile']
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Same response whether or not the number is registered, to avoid leaking enumeration signal. The link itself is only ever delivered by SMS — it is never in this response, in any environment.',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function __construct(private readonly QueueNotification $queueNotification) {}

    /**
     * Either identifier, exactly one required.
     *
     * `required_without` on both sides rather than a single `identifier`
     * field: the two are validated differently (an email has a format worth
     * checking, a mobile does not), and keeping them apart means a caller
     * who sends an address never has it silently matched against the mobile
     * column, where it would find nothing and read as a wrong password.
     *
     * @return array<string, array<int, string>>
     */
    private function identifierRules(): array
    {
        return [
            'mobile' => ['required_without:email', 'nullable', 'string', 'max:20'],
            'email' => ['required_without:mobile', 'nullable', 'email', 'max:254'],
        ];
    }

    public function requestLink(Request $request): JsonResponse
    {
        $request->validate($this->identifierRules());

        $attendee = AttendeeIdentity::resolveAttendee(
            $request->string('mobile')->value(),
            $request->string('email')->value(),
        );

        // Same response whether or not the number is registered — the
        // enumeration signal isn't worth leaking here.
        if (! $attendee) {
            return response()->json(['message' => self::CODE_SENT_MESSAGE]);
        }

        // A six-digit code rather than a 48-character link, for two reasons
        // that point the same way. It is friendlier — the reader stays in
        // the tab they started in instead of following a link that opens
        // whichever browser is default, and an OTP is the sign-in every
        // Bangladeshi phone already understands. And it is one SMS segment
        // instead of three, because the link alone was ~70 characters.
        //
        // Six digits is a million guesses, which is nothing on its own; what
        // makes it safe is the attempt ceiling in `verify()` plus the
        // fifteen-minute expiry, not the length.
        $plainToken = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $attendee->forceFill([
            'auth_token_hash' => hash('sha256', $plainToken),
            'auth_token_expires_at' => now()->addMinutes(self::TOKEN_TTL_MINUTES),
            'auth_code_attempts' => 0,
        ])->save();

        // Through the outbox action rather than `Notification::create()`.
        // Writing the row by hand skipped two things it needs, and both were
        // invisible for as long as the response also carried the token:
        // there was no template lookup, so `body_rendered` was null and the
        // SMS went out empty; and nothing dispatched `SendNotificationJob`,
        // so the row simply sat in the outbox and was never sent at all.
        //
        // `executeForRecipient` rather than `execute` because of the dedupe
        // key: `execute()` keys on (notifiable, template, channel), which is
        // right for a one-per-event notification and wrong here — the second
        // sign-in request from the same person would be silently swallowed
        // as a duplicate. The token is the suffix, so every request is its
        // own message.
        $this->queueNotification->executeForRecipient(
            notifiable: $attendee,
            templateKey: 'attendee.login_link',
            channel: 'sms',
            recipient: $attendee->mobile,
            payload: [
                'code' => $plainToken,
                'minutes' => self::TOKEN_TTL_MINUTES,
            ],
            dedupeSuffix: substr(hash('sha256', $plainToken), 0, 16),
        );

        // No `debug_token`. It used to be returned in local/testing so the
        // link could be followed without a working SMS channel — but SMS is
        // real now, and that shortcut is a complete authentication bypass
        // for anyone who can reach the endpoint: send a mobile number, get
        // back a token that signs you in as that attendee. It was gated to
        // `local`, so it was never live in production; it *was* live on a
        // developer machine holding real attendee records.
        //
        // To follow a link locally without a phone, read it from the
        // delivery log (Notifications → the row's rendered body), which is
        // the same text the recipient gets.
        return response()->json(['message' => self::CODE_SENT_MESSAGE]);
    }

    #[OAT\Post(
        path: '/attendee/auth/verify',
        summary: 'Verify a login link token and obtain an attendee bearer token',
        tags: ['Authentication'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(
                            property: 'token',
                            type: 'string',
                            description: 'Plaintext login token received via SMS',
                            required: ['token']
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Token verified, session created',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'token',
                                type: 'string',
                                description: 'API bearer token'
                            ),
                            new OAT\Property(
                                property: 'expires_at',
                                type: 'string',
                                format: 'date-time'
                            ),
                            new OAT\Property(
                                property: 'attendee',
                                properties: [
                                    new OAT\Property(property: 'ulid', type: 'string'),
                                    new OAT\Property(property: 'full_name', type: 'string'),
                                    new OAT\Property(property: 'mobile', type: 'string'),
                                ],
                                type: 'object'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Invalid or expired login link'),
        ]
    )]
    public function verify(Request $request): JsonResponse
    {
        // The mobile is required now that the secret is six digits: a code
        // that short is not unique across attendees, so matching on it alone
        // would let one person's code open whichever account happened to
        // share it.
        $request->validate($this->identifierRules() + [
            'code' => ['required', 'string'],
        ]);

        $attendee = AttendeeIdentity::resolveAttendee(
            $request->string('mobile')->value(),
            $request->string('email')->value(),
        );

        if ($attendee !== null && $attendee->auth_token_hash === null) {
            $attendee = null;
        }

        if (! $attendee || $attendee->auth_token_expires_at === null || $attendee->auth_token_expires_at->isPast()) {
            return $this->invalidCode();
        }

        // Checked and counted *before* the code is compared, so a wrong
        // guess costs an attempt whether or not it was close. Without this
        // ceiling six digits is a million tries at a few hundred requests a
        // minute; with it, five.
        if ($attendee->auth_code_attempts >= self::MAX_CODE_ATTEMPTS) {
            $this->clearCode($attendee);

            return $this->invalidCode();
        }

        if (! hash_equals((string) $attendee->auth_token_hash, hash('sha256', $request->string('code')->value()))) {
            $attendee->forceFill(['auth_code_attempts' => $attendee->auth_code_attempts + 1])->save();

            return $this->invalidCode();
        }

        $this->clearCode($attendee);

        // The extra ability is what lets `setPassword()` waive the current
        // password below. Possession of a code sent to the registered
        // number is the proof that a forgotten password cannot supply, and
        // it is the only proof this flow has — without it "Forgot password?"
        // ends with the reader signed in and still not knowing their
        // password.
        $token = $attendee->createToken(
            'attendee-session',
            ['attendee', self::PASSWORD_RESET_ABILITY],
            now()->addDays(self::SESSION_DAYS),
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => now()->addDays(self::SESSION_DAYS),
            // So the client knows to ask them to choose one — this is the
            // path an attendee with no password takes, and leaving them
            // signed in without setting one means they need another SMS next
            // time.
            'must_set_password' => ! $attendee->hasPassword(),
            'attendee' => [
                'ulid' => $attendee->ulid,
                'full_name' => $attendee->full_name,
                'mobile' => $attendee->mobile,
            ],
        ]);
    }

    #[OAT\Post(
        path: '/attendee/auth/check',
        summary: 'Whether an account exists for a mobile number or email address',
        description: 'Answers 200 either way, including for an identifier nobody holds — a 404 here means the route '
            .'is missing, never that the account is. Deliberately breaks the enumeration resistance the rest of '
            .'this controller maintains; see the note in the source before extending it.',
        tags: ['Authentication'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'mobile', type: 'string'),
                        new OAT\Property(property: 'email', type: 'string'),
                    ],
                    type: 'object',
                ),
            ),
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Whether an attendee holds that identifier'),
            new OAT\Response(response: 422, description: 'Neither identifier was supplied, or one was malformed'),
            new OAT\Response(response: 429, description: 'Too many checks'),
        ],
    )]
    /**
     * Does an account exist for this identifier?
     *
     * **This is the one route here that answers a question the rest of the
     * controller refuses to answer.** `login()` returns the same 401 for an
     * unknown number as for a wrong password, down to running a bcrypt
     * verify against a dummy hash so the two cannot be told apart with a
     * stopwatch; `requestLink()` returns the same message either way. Both
     * are deliberate: this event's attendee list is its school's alumni
     * roll, and confirming membership one number at a time is exactly what
     * those defences exist to prevent.
     *
     * It exists because the product owner asked for it, weighing that
     * against two real costs of the silence: an SMS is spent on numbers
     * that can never receive one, and someone who mistypes a digit waits
     * for a code nobody sent. That is a legitimate trade, made explicitly —
     * but it is a trade, so it is fenced:
     *
     * - a strict per-IP throttle, tighter than sign-in, because a form
     *   needs a handful of these and a scraper needs thousands;
     * - a bare boolean, never a name, a masked number or a registration —
     *   it confirms existence and nothing else;
     * - no distinction between "no such account" and "soft-deleted", so a
     *   removed attendee does not leak that they were ever here.
     *
     * If the trade is ever revisited, delete this method and the frontend
     * degrades on its own: it reads a 404 as "cannot be asked" and falls
     * back to sending the code and saying nothing.
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate($this->identifierRules());

        $attendee = AttendeeIdentity::resolveAttendee(
            $request->string('mobile')->value(),
            $request->string('email')->value(),
        );

        return response()->json(['data' => ['exists' => $attendee !== null]]);
    }

    private function clearCode(Attendee $attendee): void
    {
        $attendee->forceFill([
            'auth_token_hash' => null,
            'auth_token_expires_at' => null,
            'auth_code_attempts' => 0,
        ])->save();
    }

    /**
     * One message for every failure — wrong code, expired code, too many
     * attempts, no such number. Distinguishing them would tell an anonymous
     * caller which mobile numbers have accounts.
     */
    private function invalidCode(): JsonResponse
    {
        return response()->json(['message' => 'That code is invalid or has expired.'], 401);
    }

    #[OAT\Post(
        path: '/attendee/auth/logout',
        summary: 'Revoke the current attendee bearer token',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Logged out',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();
        $attendee->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    #[OAT\Post(
        path: '/attendee/auth/login',
        summary: 'Sign in with mobile number and password',
        description: 'The ordinary sign-in path, and the one that costs nothing: an attendee who chose a password '
            .'at checkout never needs an SMS to reach their own registration. '
            .'Answers the same `401` whether the number is unknown, has no password yet, or the password is wrong — '
            .'telling them apart would turn this into a way to discover which mobile numbers hold accounts, and '
            .'`has_password` on a 401 would be exactly that signal. An attendee with no password (created before '
            .'this existed, added by an admin, or loaded by an import) uses `request-code` instead.',
        tags: ['Authentication'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['mobile', 'password'],
                    properties: [
                        new OAT\Property(property: 'mobile', type: 'string'),
                        new OAT\Property(property: 'password', type: 'string'),
                    ],
                    type: 'object',
                ),
            ),
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Signed in'),
            new OAT\Response(response: 401, description: 'Those details do not match an account'),
            new OAT\Response(response: 429, description: 'Too many attempts'),
        ],
    )]
    public function login(Request $request): JsonResponse
    {
        $request->validate($this->identifierRules() + [
            'password' => ['required', 'string'],
        ]);

        $attendee = AttendeeIdentity::resolveAttendee(
            $request->string('mobile')->value(),
            $request->string('email')->value(),
        );

        $hasPassword = $attendee !== null && $attendee->hasPassword();

        // The comparison runs either way, against a dummy hash when there is
        // nothing real to compare with. Returning early for an unknown
        // number would make it answer in microseconds where a known one
        // costs a full bcrypt verify, and that difference is measurable —
        // account existence would be discoverable with a stopwatch despite
        // the identical response body.
        $passwordCorrect = PasswordHash::matches(
            $request->string('password')->value(),
            $hasPassword ? (string) $attendee->password : self::TIMING_DUMMY_HASH,
        );

        if ($attendee === null || ! $hasPassword || ! $passwordCorrect) {
            return response()->json(['message' => 'Those details do not match an account.'], 401);
        }

        $token = $attendee->createToken('attendee-session', ['attendee'], now()->addDays(self::SESSION_DAYS));

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => now()->addDays(self::SESSION_DAYS),
            'must_set_password' => false,
            'attendee' => [
                'ulid' => $attendee->ulid,
                'full_name' => $attendee->full_name,
                'mobile' => $attendee->mobile,
            ],
        ]);
    }

    #[OAT\Post(
        path: '/attendee/me/password',
        summary: 'Set or change the signed-in attendee\'s password',
        description: 'Setting one for the first time (after signing in with an SMS code) needs no current password, '
            .'because there is none. Changing an existing one requires it — a bearer token can outlive the moment '
            .'it was issued, and a borrowed phone must not be able to lock its owner out.',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['password', 'password_confirmation'],
                    properties: [
                        new OAT\Property(property: 'current_password', type: 'string', description: 'Required only when one is already set'),
                        new OAT\Property(property: 'password', type: 'string'),
                        new OAT\Property(property: 'password_confirmation', type: 'string'),
                    ],
                    type: 'object',
                ),
            ),
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Password saved'),
            new OAT\Response(response: 422, description: 'Validation failed, or the current password is wrong'),
        ],
    )]
    public function setPassword(Request $request): JsonResponse
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();

        // Two ways to be allowed to set a password: know the current one,
        // or hold a token minted by `verify()`, which means a code sent to
        // the registered number was answered minutes ago. The second is the
        // whole of "Forgot password?" — someone who has forgotten theirs
        // cannot satisfy the first by definition.
        $resettingByCode = $request->user()?->currentAccessToken()?->can(self::PASSWORD_RESET_ABILITY) === true;
        $mustKnowCurrent = $attendee->hasPassword() && ! $resettingByCode;

        $request->validate([
            'current_password' => [$mustKnowCurrent ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'string', PasswordRule::min(8), 'confirmed'],
        ]);

        if ($mustKnowCurrent
            && ! PasswordHash::matches($request->string('current_password')->value(), (string) $attendee->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        $attendee->forceFill([
            'password' => $request->string('password')->value(),
            'password_set_at' => now(),
            // Any code outstanding for this number is spent — leaving one
            // live would let a reset SMS from before the change still work.
            'auth_token_hash' => null,
            'auth_token_expires_at' => null,
            'auth_code_attempts' => 0,
        ])->save();

        // Every other session is revoked, this one kept. A password change
        // is what someone does when they think a session is not theirs, and
        // it has to actually end them.
        $current = $request->user()?->currentAccessToken();
        $attendee->tokens()->where('id', '!=', $current?->getKey())->delete();

        // Spent. The session continues, but it cannot reset the password a
        // second time on the strength of one code.
        if ($resettingByCode && $current !== null) {
            $current->forceFill(['abilities' => ['attendee']])->save();
        }

        return response()->json(['message' => 'Your password has been saved.']);
    }
}
