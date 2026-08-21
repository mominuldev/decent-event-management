<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Actions\ActivateQrSigningKey;
use App\Domain\Ticketing\Actions\PublishQrSigningKey;
use App\Domain\Ticketing\Actions\RetireQrSigningKey;
use App\Domain\Ticketing\Contracts\ScannerFleetStatus;
use App\Domain\Ticketing\Models\QrSigningKey;
use App\Domain\Ticketing\Services\QrSigningKeyRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PublishQrSigningKeyRequest;
use App\Http\Resources\QrSigningKeyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;

/**
 * The staged key-rotation procedure from docs/06 §6.5, as an API rather
 * than a checklist someone follows by hand in .env.
 *
 * Every route here needs `qr.rotate_signing_key` (Super Admin only — the
 * Event Manager role deliberately excludes it) and the mutating ones
 * additionally need recent re-authentication.
 */
#[OAT\Tag(name: 'QR Signing Keys')]
class QrSigningKeyController extends Controller
{
    public function __construct(
        private readonly QrSigningKeyRegistry $registry,
        private readonly ScannerFleetStatus $fleet,
    ) {}

    #[OAT\Get(
        path: '/admin/qr-signing/keys',
        summary: 'List QR signing keys and rotation readiness',
        description: 'Returns every known key with its lifecycle state, which key ids this server holds private material for but has not published yet, and — for a pending key — how much of the scanner fleet has confirmed it holds the new public key.',
        tags: ['QR Signing Keys'],
        security: [['bearerAuth' => []]],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Key inventory and readiness',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'data', type: 'array', items: new OAT\Items(type: 'object')),
                            new OAT\Property(
                                property: 'meta',
                                properties: [
                                    new OAT\Property(property: 'active_key_id', type: 'string', nullable: true),
                                    new OAT\Property(property: 'unpublished_key_ids', type: 'array', items: new OAT\Items(type: 'string'), description: 'Private key material this server holds that has not been published to devices yet'),
                                    new OAT\Property(property: 'readiness', type: 'object', nullable: true, description: 'Fleet sync status for the pending key, when one exists'),
                                ],
                                type: 'object'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing qr.rotate_signing_key'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorizeRotation($request);

        $keys = QrSigningKey::query()
            ->with(['publishedBy', 'activatedBy'])
            ->orderByRaw("FIELD(status, 'active', 'pending', 'retired')")
            ->orderByDesc('published_at')
            ->get();

        $pending = $keys->firstWhere('status', QrSigningKey::PENDING);
        $registered = $keys->pluck('key_id')->all();

        return response()->json([
            'data' => QrSigningKeyResource::collection($keys),
            'meta' => [
                'active_key_id' => $keys->firstWhere('status', QrSigningKey::ACTIVE)?->key_id,
                // The key currently signing is excluded even when it has no
                // row yet: it is not a rotation candidate, and offering it
                // invites the operator to request the one publish that is
                // refused.
                'unpublished_key_ids' => array_values(array_diff(
                    $this->registry->availablePrivateKeyIds(),
                    $registered,
                    array_filter([(string) config('services.qr_signing.active_key_id')]),
                )),
                'readiness' => $pending !== null
                    ? $this->fleet->syncStatusSince($pending->publishedAt())
                    : null,
            ],
        ]);
    }

    #[OAT\Post(
        path: '/admin/qr-signing/keys',
        summary: 'Publish a new signing key to scanner devices (rotation step 1)',
        description: 'Registers a key id this server already holds the private half of, deriving and publishing its public key. Devices pick it up on their next manifest sync. Nothing signs with it until it is activated.',
        tags: ['QR Signing Keys'],
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['key_id'],
                    properties: [new OAT\Property(property: 'key_id', type: 'string', maxLength: 32)]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 201, description: 'Key published to devices'),
            new OAT\Response(response: 403, description: 'Missing qr.rotate_signing_key, or re-authentication required'),
            new OAT\Response(response: 422, description: 'Key already registered, or no private key available for it'),
        ]
    )]
    public function store(PublishQrSigningKeyRequest $request, PublishQrSigningKey $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $key = $action->execute(
            $request->string('key_id')->value(),
            $user,
            $request->ip(),
            $request->header('X-Request-Id'),
        );

        return response()->json(['data' => new QrSigningKeyResource($key)], 201);
    }

    #[OAT\Post(
        path: '/admin/qr-signing/keys/{key}/activate',
        summary: 'Start signing new tickets with this key (rotation step 3)',
        description: 'Refuses unless every active scanner device has completed a manifest sync since the key was published — activating early is what breaks a gate. `force` overrides that and is recorded distinctly in the audit trail.',
        tags: ['QR Signing Keys'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'key', description: 'Signing key ULID', schema: new OAT\Schema(type: 'string'))],
        requestBody: new OAT\RequestBody(
            required: false,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(properties: [new OAT\Property(property: 'force', type: 'boolean')])
            )
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Key activated; the previous key is retired but still verifies'),
            new OAT\Response(response: 403, description: 'Missing qr.rotate_signing_key, or re-authentication required'),
            new OAT\Response(response: 422, description: 'Devices not synced, or no private key available for this key'),
        ]
    )]
    public function activate(Request $request, QrSigningKey $key, ActivateQrSigningKey $action): JsonResponse
    {
        $this->authorizeRotation($request);
        $request->validate(['force' => ['sometimes', 'boolean']]);

        /** @var User $user */
        $user = $request->user();

        $key = $action->execute(
            $key,
            $user,
            $request->boolean('force'),
            $request->ip(),
            $request->header('X-Request-Id'),
        );

        return response()->json(['data' => new QrSigningKeyResource($key)]);
    }

    #[OAT\Post(
        path: '/admin/qr-signing/keys/{key}/retire',
        summary: 'Call off a published-but-unactivated key',
        description: 'Retires a pending key. A retired key still verifies tickets already signed with it; it simply stops being a rotation candidate. The active key cannot be retired this way.',
        tags: ['QR Signing Keys'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'key', description: 'Signing key ULID', schema: new OAT\Schema(type: 'string'))],
        responses: [
            new OAT\Response(response: 200, description: 'Key retired'),
            new OAT\Response(response: 403, description: 'Missing qr.rotate_signing_key, or re-authentication required'),
            new OAT\Response(response: 422, description: 'Cannot retire the active signing key'),
        ]
    )]
    public function retire(Request $request, QrSigningKey $key, RetireQrSigningKey $action): JsonResponse
    {
        $this->authorizeRotation($request);

        /** @var User $user */
        $user = $request->user();

        $key = $action->execute($key, $user, $request->ip(), $request->header('X-Request-Id'));

        return response()->json(['data' => new QrSigningKeyResource($key)]);
    }

    private function authorizeRotation(Request $request): void
    {
        abort_unless($request->user()?->can('qr.rotate_signing_key') ?? false, 403, 'Missing permission: qr.rotate_signing_key');
    }
}
