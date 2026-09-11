<?php

namespace App\Http\Controllers\Api\Attendee;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Registration\Support\AttendeeIdentity;
use App\Domain\Registration\Support\AttendeeNameMatch;
use App\Domain\Shared\Models\ActivityLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\UpdateLookupProfileRequest;
use App\Http\Resources\AttendeeResource;
use App\Http\Resources\RegistrationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OAT;

/**
 * "Find my ticket" — look up and correct your own details with no password
 * and no SMS, by stating your mobile number or email address and the name
 * you registered under.
 *
 * ## What this is, stated plainly
 *
 * A deliberately weaker credential than the rest of this application uses,
 * adopted at the product owner's request. Everywhere else an attendee
 * proves possession of something: a password they chose, or a six-digit
 * code sent to the handset on the record. Here they prove knowledge of two
 * facts about somebody — a phone number and a name — neither of which is
 * secret in any ordinary sense, and both of which appear together on the
 * public attendees directory this same site publishes.
 *
 * That trade was made knowingly, for a real reason: the audience is a
 * century of alumni, a large share of them elderly, and an OTP round trip
 * loses people who then telephone the office instead. It is not the code's
 * place to relitigate it. It *is* the code's place to make sure the weaker
 * credential buys strictly less than the stronger one, which is what the
 * rest of this file is about.
 *
 * ## What a lookup session cannot do
 *
 * The token minted here carries the ability `attendee-lookup` and **not**
 * `attendee`. Every existing self-service route is gated on `abilities:attendee`,
 * so this token is refused by all of them — that is one middleware check
 * standing between a guessed name and the things that would actually hurt:
 *
 * - the QR payload and the ticket PDF, which *are* admission — a lookup
 *   that handed those over would be a way to walk in on somebody else's
 *   ticket, which is the one outcome that must not be purchasable with a
 *   guess;
 * - cancelling a registration, which destroys a paid seat;
 * - setting a password, which would convert a lucky guess into permanent
 *   ownership of the account.
 *
 * Within the lookup surface itself, the email address is withheld from the
 * update path too — see {@see UpdateLookupProfileRequest} for why.
 *
 * So the flow reads registration status, ticket numbers and profile
 * details, and writes corrections to profile details. Anyone wanting the
 * ticket itself signs in properly; that path is untouched and still exists.
 *
 * ## Why the failures all look the same
 *
 * One message and one status for "no such number", "no such address" and
 * "that is not the name on the record". Telling them apart would make this
 * a name oracle: feed it a mobile number, watch for the answer to change,
 * and read back the registered name — which is the only secret the flow
 * has. The rate limiter (`find-my-ticket`, keyed per identifier and per IP)
 * is what bounds guessing at the name once the number is known.
 */
#[OAT\Tag(name: 'Attendee Self-Service')]
class FindMyTicketController extends Controller
{
    /**
     * The ability the lookup token carries. Deliberately not `attendee` —
     * that word is what every route worth protecting checks for.
     */
    public const string ABILITY = 'attendee-lookup';

    /**
     * Long enough to read a page and correct a few fields, short enough
     * that a session left open on a shared phone is not a standing key.
     * The full sign-in lasts 30 days; this is the same trade made the
     * other way, because the credential behind it is so much weaker.
     */
    private const int SESSION_MINUTES = 60;

    /**
     * Said for a number nobody holds, an address nobody holds, and a name
     * that does not match — see the class docblock.
     */
    private const string NO_MATCH_MESSAGE = 'We could not find a registration with those details. Check the name is spelled as it was on the registration form, and that the mobile number or email address is the one you registered with.';

    #[OAT\Post(
        path: '/attendee/find-my-ticket',
        summary: 'Open a passwordless lookup session from a mobile number or email address plus the registered name',
        description: 'Grants a short-lived token that can read the attendee\'s own registrations and correct their '
            .'profile, and nothing else — it is refused by every route gated on the `attendee` ability, including '
            .'the QR payload, the ticket PDF, registration cancellation and password changes. Answers the same 404 '
            .'for an unknown identifier as for a name that does not match, so it cannot be used to read back the '
            .'name on a record.',
        tags: ['Attendee Self-Service'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['full_name'],
                    properties: [
                        new OAT\Property(property: 'mobile', type: 'string', description: 'Required unless `email` is given'),
                        new OAT\Property(property: 'email', type: 'string', format: 'email', description: 'Required unless `mobile` is given'),
                        new OAT\Property(property: 'full_name', type: 'string', description: 'Either the Latin or the Bangla name on the registration; case, punctuation and spacing are ignored'),
                    ],
                    type: 'object',
                ),
            ),
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Lookup session opened'),
            new OAT\Response(response: 404, description: 'No registration matches those details'),
            new OAT\Response(response: 422, description: 'Neither identifier was supplied, or one was malformed'),
            new OAT\Response(response: 429, description: 'Too many lookups'),
        ],
    )]
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            // Same shape as the sign-in routes: two separately-validated
            // fields rather than one `identifier`, so an address is never
            // silently matched against the mobile column where it would
            // find nothing and read as a wrong name.
            'mobile' => ['required_without:email', 'nullable', 'string', 'max:20'],
            'email' => ['required_without:mobile', 'nullable', 'email', 'max:254'],
            'full_name' => ['required', 'string', 'max:150'],
        ]);

        $attendee = AttendeeIdentity::resolveAttendee(
            $request->string('mobile')->value(),
            $request->string('email')->value(),
        );

        if ($attendee === null || ! AttendeeNameMatch::matches($attendee, $request->string('full_name')->value())) {
            return response()->json([
                'code' => 'lookup_no_match',
                'message' => self::NO_MATCH_MESSAGE,
                'request_id' => $request->header('X-Request-Id'),
            ], 404);
        }

        $expiresAt = now()->addMinutes(self::SESSION_MINUTES);
        $token = $attendee->createToken('attendee-lookup', [self::ABILITY], $expiresAt);

        // Audited, unlike the ordinary sign-in, and for a reason specific to
        // this route: it is the one way into an account that leaves no trace
        // anywhere else — no SMS was sent, no password was used, so if a
        // record is later found altered there would otherwise be nothing to
        // say a passwordless session had ever been opened on it. Written
        // here rather than in an Action because there is no Action; the
        // minting *is* the operation.
        ActivityLog::create([
            'log_name' => 'registration',
            'event' => 'attendee_lookup_session_opened',
            'description' => "Passwordless lookup session opened for attendee {$attendee->ulid}",
            'causer_type' => $attendee->getMorphClass(),
            'causer_id' => $attendee->id,
            'subject_type' => $attendee->getMorphClass(),
            'subject_id' => $attendee->id,
            'properties' => [
                // Which identifier was used, never its value — the row is
                // an audit trail, not a second copy of the contact details.
                'identifier' => $request->filled('mobile') ? 'mobile' : 'email',
                'expires_at' => $expiresAt->toISOString(),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'request_id' => $request->header('X-Request-Id'),
            'severity' => 'notice',
            'created_at' => now(),
        ]);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toISOString(),
            'attendee' => [
                'ulid' => $attendee->ulid,
                'full_name' => $attendee->full_name,
                'full_name_bn' => $attendee->full_name_bn,
            ],
        ]);
    }

    #[OAT\Get(
        path: '/attendee/find-my-ticket/me',
        summary: 'The profile behind an open lookup session',
        security: [['bearerAuth' => []]],
        tags: ['Attendee Self-Service'],
        responses: [
            new OAT\Response(response: 200, description: 'The attendee profile'),
            new OAT\Response(response: 403, description: 'The token is not a lookup token'),
        ],
    )]
    public function show(Request $request): AttendeeResource
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();

        return new AttendeeResource($attendee->load('profilePhoto.thumbnail'));
    }

    #[OAT\Patch(
        path: '/attendee/find-my-ticket/me',
        summary: 'Correct the profile behind an open lookup session',
        description: 'Accepts every field the signed-in profile update accepts except `email`, which is one of the '
            .'two identifiers this lookup matches on and the channel the ticket is delivered to.',
        security: [['bearerAuth' => []]],
        tags: ['Attendee Self-Service'],
        responses: [
            new OAT\Response(response: 200, description: 'Profile updated'),
            new OAT\Response(response: 403, description: 'The token is not a lookup token'),
            new OAT\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateLookupProfileRequest $request): AttendeeResource
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();

        $attendee->update($request->validated());

        return new AttendeeResource($attendee->load('profilePhoto.thumbnail'));
    }

    #[OAT\Get(
        path: '/attendee/find-my-ticket/registrations',
        summary: 'The registrations behind an open lookup session',
        description: 'Ticket numbers and statuses only. The QR payload and the signed ticket-image URL are not '
            .'loaded, so they cannot appear in the response — a lookup session may see *that* a ticket exists, '
            .'never the code that admits its holder.',
        security: [['bearerAuth' => []]],
        tags: ['Attendee Self-Service'],
        responses: [
            new OAT\Response(response: 200, description: 'Registrations belonging to the looked-up attendee'),
            new OAT\Response(response: 403, description: 'The token is not a lookup token'),
        ],
    )]
    public function registrations(Request $request): AnonymousResourceCollection
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();

        // Spelled out here rather than delegating to
        // Attendee\RegistrationController::index(), which today loads an
        // identical set. The duplication is the safeguard: `TicketResource`
        // publishes `qr_code_payload` and `qr_code_image_url` behind
        // `whenLoaded('qrCode')`, so the difference between this endpoint
        // being safe and it handing out admission credentials is one
        // relation in an eager-load list. Sharing the list would mean a
        // change made for the signed-in dashboard could silently widen the
        // passwordless one. Scoped on `attendee_id` at the builder, per the
        // ownership rule in CLAUDE.md.
        $registrations = Registration::with(['attendee', 'guests', 'ticketType', 'eventSession', 'tickets'])
            ->where('attendee_id', $attendee->id)
            ->orderByDesc('id')
            ->get();

        return RegistrationResource::collection($registrations);
    }
}
