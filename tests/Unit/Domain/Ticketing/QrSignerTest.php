<?php

namespace Tests\Unit\Domain\Ticketing;

use App\Domain\Ticketing\Services\QrSigner;
use App\Domain\Ticketing\Support\QrPayload;
use Tests\TestCase;

/**
 * Ed25519 signing (docs/06 §6.5). Uses the fixed test keypair from
 * phpunit.xml unless a test constructs its own config to exercise
 * multi-key rotation.
 */
class QrSignerTest extends TestCase
{
    public function test_a_signed_payload_round_trips_and_verifies(): void
    {
        $signer = new QrSigner;
        $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $exp = now()->addYear()->timestamp;

        $signed = $signer->sign($ulid, 3, $exp);

        $this->assertStringStartsWith('DTM1.'.$ulid.'.3.'.$exp.'.', $signed['payload']);
        $this->assertSame(hash('sha256', $signed['payload']), $signed['payload_hash']);
        $this->assertSame($signer->activeKeyId(), $signed['signing_key_id']);

        $payload = QrPayload::parse($signed['payload']);

        $this->assertNotNull($payload);
        $this->assertTrue($signer->verify($payload));
    }

    public function test_a_single_bit_mutation_in_the_signature_fails_verification(): void
    {
        $signer = new QrSigner;
        $signed = $signer->sign('01ARZ3NDEKTSV4RRFFQ69G5FAV', 1, now()->addYear()->timestamp);

        $mutatedSignature = $signed['signature'][0] === 'A' ? 'B'.substr($signed['signature'], 1) : 'A'.substr($signed['signature'], 1);
        $tamperedPayload = str_replace('.'.$signed['signature'], '.'.$mutatedSignature, $signed['payload']);

        $parsed = QrPayload::parse($tamperedPayload);

        $this->assertNotNull($parsed);
        $this->assertFalse($signer->verify($parsed));
    }

    public function test_a_mutated_admits_total_invalidates_the_signature(): void
    {
        // admits_total is inside the signed portion — bumping it to grant
        // extra admissions without re-signing must fail verification.
        $signer = new QrSigner;
        $signed = $signer->sign('01ARZ3NDEKTSV4RRFFQ69G5FAV', 1, now()->addYear()->timestamp);

        $tampered = str_replace('.1.', '.9.', $signed['payload']);
        $parsed = QrPayload::parse($tampered);

        $this->assertNotNull($parsed);
        $this->assertFalse($signer->verify($parsed));
    }

    public function test_an_unknown_signing_key_id_fails_verification(): void
    {
        $signer = new QrSigner;
        $payload = QrPayload::parse('DTM1.01ARZ3NDEKTSV4RRFFQ69G5FAV.1.'.now()->addYear()->timestamp.'.no-such-key.'.str_repeat('A', 86));

        $this->assertNotNull($payload);
        $this->assertFalse($signer->verify($payload));
    }

    public function test_a_retired_key_still_verifies_after_rotation(): void
    {
        $oldKeypair = sodium_crypto_sign_keypair();
        $newKeypair = sodium_crypto_sign_keypair();

        $before = new QrSigner([
            'active_key_id' => 'old-key',
            'active_private_key' => base64_encode(sodium_crypto_sign_secretkey($oldKeypair)),
            'retired_public_keys' => [],
        ]);
        $signedUnderOldKey = $before->sign('01ARZ3NDEKTSV4RRFFQ69G5FAV', 1, now()->addYear()->timestamp);

        // Simulate the rotation: new key becomes active, old key's public
        // component is retained for verification only.
        $after = new QrSigner([
            'active_key_id' => 'new-key',
            'active_private_key' => base64_encode(sodium_crypto_sign_secretkey($newKeypair)),
            'retired_public_keys' => ['old-key' => base64_encode(sodium_crypto_sign_publickey($oldKeypair))],
        ]);

        $parsed = QrPayload::parse($signedUnderOldKey['payload']);
        $this->assertNotNull($parsed);
        $this->assertTrue($after->verify($parsed));

        // New tickets sign under the new key.
        $signedUnderNewKey = $after->sign('01ARZ3NDEKTSV4RRFFQ69G5FAV', 1, now()->addYear()->timestamp);
        $this->assertSame('new-key', $signedUnderNewKey['signing_key_id']);
    }

    public function test_public_keys_publishes_every_known_key_for_the_manifest(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $signer = new QrSigner([
            'active_key_id' => 'active',
            'active_private_key' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
            'retired_public_keys' => ['retired' => base64_encode(random_bytes(32))],
        ]);

        $keys = $signer->publicKeys();

        $this->assertArrayHasKey('active', $keys);
        $this->assertArrayHasKey('retired', $keys);
    }
}
