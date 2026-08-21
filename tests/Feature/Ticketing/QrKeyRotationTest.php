<?php

namespace Tests\Feature\Ticketing;

use App\Domain\CheckIn\Models\CheckInDevice;
use App\Domain\Notification\Models\Notification;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\QrSigningKey;
use App\Domain\Ticketing\Services\QrSigner;
use App\Domain\Ticketing\Support\QrPayload;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The staged key rotation from docs/06 §6.5, and the Phase 6 exit criterion
 * it exists to satisfy: "key rotation completes without invalidating
 * existing tickets".
 *
 * The failure this guards against is not a crash. Activating a new signing
 * key before every scanner holds its public key produces a server that looks
 * perfectly healthy and a gate that rejects every ticket issued from that
 * moment on — discovered, in the worst case, by a queue of alumni.
 */
class QrKeyRotationTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT_KEY = 'key-current';

    private const NEXT_KEY = 'key-next';

    private User $superAdmin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        // Two real keypairs: the one currently signing, and its replacement
        // sitting available-but-inert, which is the state an operator is in
        // after running `qr-signing:generate-key` and deploying.
        [$currentSecret] = $this->keypair();
        [$nextSecret] = $this->keypair();

        config([
            'services.qr_signing.active_key_id' => self::CURRENT_KEY,
            'services.qr_signing.active_private_key' => $currentSecret,
            'services.qr_signing.retired_public_keys' => [],
            'services.qr_signing.private_keys' => [
                self::CURRENT_KEY => $currentSecret,
                self::NEXT_KEY => $nextSecret,
            ],
        ]);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole('Super Admin');
        $this->token = $this->superAdmin->createToken('test', ['admin'], now()->addHours(8))->plainTextToken;
    }

    // ---------------------------------------------------------------- publish

    public function test_publishing_a_key_derives_its_public_half_and_publishes_it_without_changing_the_signing_key(): void
    {
        $this->reauthenticate();

        $response = $this->withToken($this->token)
            ->postJson(route('api.v1.admin.qr-signing.keys.store'), ['key_id' => self::NEXT_KEY]);

        $response->assertStatus(201)
            ->assertJsonPath('data.key_id', self::NEXT_KEY)
            ->assertJsonPath('data.status', QrSigningKey::PENDING);

        // Derived server-side from the private half — nothing pasted through
        // the API, so a published key is always one that can actually sign.
        $expectedPublic = base64_encode(sodium_crypto_sign_publickey_from_secretkey(
            (string) base64_decode((string) config('services.qr_signing.private_keys.'.self::NEXT_KEY), true)
        ));
        $this->assertSame($expectedPublic, $response->json('data.public_key'));

        $signer = app(QrSigner::class);

        // Devices can now verify the new key...
        $this->assertArrayHasKey(self::NEXT_KEY, $signer->publicKeys());
        // ...but nothing is signed with it yet. That separation is the whole
        // point of publishing being its own step.
        $this->assertSame(self::CURRENT_KEY, $signer->activeKeyId());
    }

    public function test_publishing_a_key_this_server_has_no_private_half_for_is_refused(): void
    {
        $this->reauthenticate();

        $this->withToken($this->token)
            ->postJson(route('api.v1.admin.qr-signing.keys.store'), ['key_id' => 'key-nobody-has'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'signing_key_unavailable');

        $this->assertDatabaseCount('qr_signing_keys', 0);
    }

    /**
     * Asking to publish the key that is already signing.
     *
     * Not a hypothetical: before the fix this was a unique-constraint **500**,
     * because publishing adopts the incumbent into the table and then tried to
     * insert the same key id again. Every other test here uses a next key
     * distinct from the incumbent, so nothing caught it until the real
     * endpoint was called by hand. It is caller error, so it answers 422.
     */
    public function test_publishing_the_key_that_is_already_signing_is_refused_cleanly(): void
    {
        $this->reauthenticate();

        $this->withToken($this->token)
            ->postJson(route('api.v1.admin.qr-signing.keys.store'), ['key_id' => self::CURRENT_KEY])
            ->assertStatus(422)
            ->assertJsonPath('code', 'signing_key_already_registered');

        // And the refusal left nothing behind — the incumbent adoption that
        // runs first is rolled back with the failed publish.
        $this->assertDatabaseCount('qr_signing_keys', 0);
        $this->assertSame(self::CURRENT_KEY, app(QrSigner::class)->activeKeyId());
    }

    /** The console must not offer the active key as something to publish. */
    public function test_the_index_does_not_offer_the_active_key_as_a_rotation_candidate(): void
    {
        $this->reauthenticate();

        $meta = $this->withToken($this->token)
            ->getJson(route('api.v1.admin.qr-signing.keys.index'))
            ->assertStatus(200)
            ->json('meta');

        $this->assertNotContains(self::CURRENT_KEY, $meta['unpublished_key_ids']);
        $this->assertContains(self::NEXT_KEY, $meta['unpublished_key_ids']);
    }

    public function test_publishing_the_same_key_twice_is_refused(): void
    {
        $this->reauthenticate();
        $this->publishNextKey();

        $this->withToken($this->token)
            ->postJson(route('api.v1.admin.qr-signing.keys.store'), ['key_id' => self::NEXT_KEY])
            ->assertStatus(422)
            ->assertJsonPath('code', 'signing_key_already_registered');
    }

    // --------------------------------------------------------------- ordering

    public function test_activation_is_refused_while_any_active_device_has_not_synced_since_publication(): void
    {
        $this->reauthenticate();
        $key = $this->publishNextKey();

        $this->device('GATE-01', syncedAt: now()->addMinute());
        $stale = $this->device('GATE-02', syncedAt: now()->subDay());

        $this->withToken($this->token)
            ->postJson(route('api.v1.admin.qr-signing.keys.activate', $key))
            ->assertStatus(422)
            ->assertJsonPath('code', 'devices_not_synced');

        $this->assertSame(QrSigningKey::PENDING, $key->refresh()->status);
        $this->assertSame(self::CURRENT_KEY, app(QrSigner::class)->activeKeyId());

        // And the console names the device holding it up, rather than making
        // the operator go and work out which one.
        $readiness = $this->withToken($this->token)
            ->getJson(route('api.v1.admin.qr-signing.keys.index'))
            ->assertStatus(200)
            ->json('meta.readiness');

        $this->assertSame(2, $readiness['total']);
        $this->assertSame(1, $readiness['synced']);
        $this->assertSame($stale->device_code, $readiness['outstanding'][0]['device_code']);
    }

    public function test_a_device_that_never_synced_at_all_blocks_activation(): void
    {
        $this->reauthenticate();
        $key = $this->publishNextKey();
        $this->device('GATE-01', syncedAt: null);

        $this->withToken($this->token)
            ->postJson(route('api.v1.admin.qr-signing.keys.activate', $key))
            ->assertStatus(422)
            ->assertJsonPath('code', 'devices_not_synced');
    }

    public function test_a_revoked_device_does_not_hold_a_rotation_open_forever(): void
    {
        $this->reauthenticate();
        $key = $this->publishNextKey();

        $this->device('GATE-01', syncedAt: now()->addMinute());
        $this->device('GATE-02', syncedAt: now()->subDay(), status: 'revoked');

        $this->withToken($this->token)
            ->postJson(route('api.v1.admin.qr-signing.keys.activate', $key))
            ->assertStatus(200);
    }

    public function test_activation_succeeds_once_every_device_has_synced_and_retires_the_previous_key(): void
    {
        $this->reauthenticate();
        $key = $this->publishNextKey();
        $this->device('GATE-01', syncedAt: now()->addMinute());

        $this->withToken($this->token)
            ->postJson(route('api.v1.admin.qr-signing.keys.activate', $key))
            ->assertStatus(200)
            ->assertJsonPath('data.status', QrSigningKey::ACTIVE);

        $this->assertSame(self::NEXT_KEY, app(QrSigner::class)->activeKeyId());

        // The outgoing key is retired from *signing*. It is still published,
        // which is what keeps tickets already in circulation working.
        $previous = QrSigningKey::query()->where('key_id', self::CURRENT_KEY)->first();
        $this->assertNotNull($previous);
        $this->assertSame(QrSigningKey::RETIRED, $previous->status);
    }

    // -------------------------------------------------------- exit criterion

    public function test_a_ticket_signed_before_the_rotation_still_verifies_after_it(): void
    {
        $before = app(QrSigner::class);
        $issued = $before->sign('01JBEFORETHEROTATION000000', 2, now()->addYear()->getTimestamp());
        $this->assertSame(self::CURRENT_KEY, $issued['signing_key_id']);

        $this->reauthenticate();
        $key = $this->publishNextKey();
        $this->device('GATE-01', syncedAt: now()->addMinute());
        $this->withToken($this->token)
            ->postJson(route('api.v1.admin.qr-signing.keys.activate', $key))
            ->assertStatus(200);

        $after = app(QrSigner::class);

        // docs/08 Phase 6: "key rotation completes without invalidating
        // existing tickets". This is that criterion, asserted directly.
        $this->assertTrue(
            $after->verify(QrPayload::parse($issued['payload'])),
            'A ticket issued before the rotation no longer verifies — every printed ticket just became worthless.'
        );

        // And the new key is what signs from here on.
        $this->assertSame(self::NEXT_KEY, $after->sign('01JAFTERTHEROTATION0000000', 1, now()->addYear()->getTimestamp())['signing_key_id']);
    }

    public function test_the_retired_key_is_still_published_to_devices_after_rotation(): void
    {
        $this->reauthenticate();
        $key = $this->publishNextKey();
        $this->device('GATE-01', syncedAt: now()->addMinute());
        $this->withToken($this->token)->postJson(route('api.v1.admin.qr-signing.keys.activate', $key));

        $published = app(QrSigner::class)->publicKeys();

        $this->assertArrayHasKey(self::CURRENT_KEY, $published);
        $this->assertArrayHasKey(self::NEXT_KEY, $published);
    }

    /**
     * A server that does not hold the active key's private half must still
     * publish its public half.
     *
     * Found by running a real rotation and noticing the manifest's key list
     * had gone down to one entry: QrSigner derives the active key's public
     * half from its secret, so an instance without that secret — mid
     * rolling-deploy, say — published a manifest with the active key missing
     * altogether, and any device syncing from it would reject every ticket
     * signed with that key.
     */
    public function test_the_active_key_is_published_even_where_its_private_half_is_absent(): void
    {
        $this->reauthenticate();
        $key = $this->publishNextKey();
        $this->device('GATE-01', syncedAt: now()->addMinute());
        $this->withToken($this->token)->postJson(route('api.v1.admin.qr-signing.keys.activate', $key))->assertStatus(200);

        // An instance that never received the new key material.
        config([
            'services.qr_signing.active_private_key' => null,
            'services.qr_signing.private_keys' => [],
        ]);

        $published = app(QrSigner::class)->publicKeys();

        $this->assertArrayHasKey(self::NEXT_KEY, $published, 'The active key vanished from the published set.');
        $this->assertArrayHasKey(self::CURRENT_KEY, $published);
    }

    // ------------------------------------------------------------ force / audit

    public function test_forcing_activation_past_an_unsynced_device_works_and_is_audited_distinctly(): void
    {
        $this->reauthenticate();
        $key = $this->publishNextKey();
        $this->device('GATE-01', syncedAt: now()->subDay());

        $this->withToken($this->token)
            ->postJson(route('api.v1.admin.qr-signing.keys.activate', $key), ['force' => true])
            ->assertStatus(200);

        $this->assertSame(self::NEXT_KEY, app(QrSigner::class)->activeKeyId());

        $log = ActivityLog::query()->where('log_name', 'qr_signing')->where('event', 'key_activated_forced')->first();
        $this->assertNotNull($log, 'A forced rotation must be distinguishable in the audit trail from a clean one.');
        $this->assertTrue($log->properties['forced']);
        $this->assertCount(1, $log->properties['devices_outstanding']);
    }

    public function test_publishing_and_activating_are_both_written_to_the_activity_log(): void
    {
        $this->reauthenticate();
        $key = $this->publishNextKey();
        $this->device('GATE-01', syncedAt: now()->addMinute());
        $this->withToken($this->token)->postJson(route('api.v1.admin.qr-signing.keys.activate', $key));

        $this->assertDatabaseHas('activity_logs', ['log_name' => 'qr_signing', 'event' => 'key_published']);
        $this->assertDatabaseHas('activity_logs', ['log_name' => 'qr_signing', 'event' => 'key_activated']);
    }

    // ----------------------------------------------------------------- retire

    public function test_the_active_signing_key_cannot_be_retired(): void
    {
        $this->reauthenticate();
        $key = $this->publishNextKey();
        $this->device('GATE-01', syncedAt: now()->addMinute());
        $this->withToken($this->token)->postJson(route('api.v1.admin.qr-signing.keys.activate', $key));

        $this->withToken($this->token)
            ->postJson(route('api.v1.admin.qr-signing.keys.retire', $key->refresh()))
            ->assertStatus(422)
            ->assertJsonPath('code', 'cannot_retire_active_key');
    }

    public function test_a_published_key_can_be_called_off_before_it_ever_signs(): void
    {
        $this->reauthenticate();
        $key = $this->publishNextKey();

        $this->withToken($this->token)
            ->postJson(route('api.v1.admin.qr-signing.keys.retire', $key))
            ->assertStatus(200)
            ->assertJsonPath('data.status', QrSigningKey::RETIRED);

        $this->assertSame(self::CURRENT_KEY, app(QrSigner::class)->activeKeyId());
    }

    // ------------------------------------------------------------- gatekeeping

    public function test_rotation_requires_recent_reauthentication(): void
    {
        // Authenticated, permitted — but no password confirmation.
        $this->withToken($this->token)
            ->postJson(route('api.v1.admin.qr-signing.keys.store'), ['key_id' => self::NEXT_KEY])
            ->assertStatus(403)
            ->assertJsonPath('code', 'reauthentication_required');

        $this->assertDatabaseCount('qr_signing_keys', 0);

        $this->reauthenticate();

        $this->withToken($this->token)
            ->postJson(route('api.v1.admin.qr-signing.keys.store'), ['key_id' => self::NEXT_KEY])
            ->assertStatus(201);
    }

    public function test_reauthentication_is_bound_to_the_token_that_confirmed_it(): void
    {
        $this->reauthenticate();

        // A second session for the same person is a different device, and
        // confirms on its own.
        $otherToken = $this->superAdmin->createToken('other-laptop', ['admin'], now()->addHours(8))->plainTextToken;

        // Laravel's auth guard caches the user it resolved for the previous
        // request inside one test method, so without this the second request
        // silently reuses the first token and the test proves nothing. Each
        // real request is a fresh process, where this cannot happen.
        $this->app['auth']->forgetGuards();

        $this->withToken($otherToken)
            ->postJson(route('api.v1.admin.qr-signing.keys.store'), ['key_id' => self::NEXT_KEY])
            ->assertStatus(403)
            ->assertJsonPath('code', 'reauthentication_required');
    }

    public function test_an_event_manager_may_not_rotate_the_signing_key(): void
    {
        $manager = User::factory()->create(['status' => 'active']);
        $manager->assignRole('Event Manager');
        $token = $manager->createToken('em', ['admin'], now()->addHours(8))->plainTextToken;

        $this->postJson(route('api.v1.admin.auth.reauth'), ['password' => 'password'], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        $this->withToken($token)
            ->postJson(route('api.v1.admin.qr-signing.keys.store'), ['key_id' => self::NEXT_KEY])
            ->assertStatus(403);

        $this->withToken($token)
            ->getJson(route('api.v1.admin.qr-signing.keys.index'))
            ->assertStatus(403);
    }

    public function test_reauthentication_rejects_a_wrong_password_without_locking_the_account(): void
    {
        $this->withToken($this->token)
            ->postJson(route('api.v1.admin.auth.reauth'), ['password' => 'not-the-password'])
            ->assertStatus(422);

        $this->assertNull($this->superAdmin->refresh()->locked_until);
        $this->assertSame(0, $this->superAdmin->failed_login_attempts);
    }

    // ------------------------------------------------------------ notification

    public function test_rotation_notifies_every_active_event_manager(): void
    {
        $this->seed(NotificationTemplateSeeder::class);

        $managerA = User::factory()->create(['status' => 'active', 'email' => 'a@example.test']);
        $managerA->assignRole('Event Manager');
        $managerB = User::factory()->create(['status' => 'active', 'email' => 'b@example.test']);
        $managerB->assignRole('Event Manager');
        $inactive = User::factory()->create(['status' => 'suspended', 'email' => 'c@example.test']);
        $inactive->assignRole('Event Manager');

        $this->reauthenticate();
        $key = $this->publishNextKey();
        $this->device('GATE-01', syncedAt: now()->addMinute());
        $this->withToken($this->token)->postJson(route('api.v1.admin.qr-signing.keys.activate', $key))->assertStatus(200);

        $recipients = Notification::query()
            ->where('template_key', 'qr_signing_key_rotated')
            ->pluck('recipient')
            ->all();

        sort($recipients);
        $this->assertSame(['a@example.test', 'b@example.test'], $recipients);
    }

    // ------------------------------------------------------------------ guards

    public function test_the_database_refuses_a_second_active_key(): void
    {
        QrSigningKey::create(['key_id' => 'k1', 'public_key' => 'p1', 'status' => QrSigningKey::ACTIVE]);

        $this->expectException(QueryException::class);
        QrSigningKey::create(['key_id' => 'k2', 'public_key' => 'p2', 'status' => QrSigningKey::ACTIVE]);
    }

    public function test_the_index_reports_key_material_this_server_holds_but_has_not_published(): void
    {
        $this->reauthenticate();

        $meta = $this->withToken($this->token)
            ->getJson(route('api.v1.admin.qr-signing.keys.index'))
            ->assertStatus(200)
            ->json('meta');

        $this->assertContains(self::NEXT_KEY, $meta['unpublished_key_ids']);
        $this->assertNull($meta['readiness'], 'With nothing pending there is no rotation in flight to report on.');
    }

    // ----------------------------------------------------------------- helpers

    private function reauthenticate(): void
    {
        $this->withToken($this->token)
            ->postJson(route('api.v1.admin.auth.reauth'), ['password' => 'password'])
            ->assertStatus(200)
            ->assertJsonPath('confirmed', true);
    }

    private function publishNextKey(): QrSigningKey
    {
        $this->withToken($this->token)
            ->postJson(route('api.v1.admin.qr-signing.keys.store'), ['key_id' => self::NEXT_KEY])
            ->assertStatus(201);

        $key = QrSigningKey::query()->where('key_id', self::NEXT_KEY)->first();
        $this->assertNotNull($key);

        return $key;
    }

    private function device(string $code, ?Carbon $syncedAt, string $status = 'active'): CheckInDevice
    {
        return CheckInDevice::factory()->create([
            'device_code' => $code,
            'status' => $status,
            'last_sync_at' => $syncedAt,
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function keypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            base64_encode(sodium_crypto_sign_secretkey($pair)),
            base64_encode(sodium_crypto_sign_publickey($pair)),
        ];
    }
}
