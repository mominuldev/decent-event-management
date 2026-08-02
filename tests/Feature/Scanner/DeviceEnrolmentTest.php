<?php

namespace Tests\Feature\Scanner;

use App\Domain\CheckIn\Models\VolunteerProfile;
use App\Domain\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeviceEnrolmentTest extends TestCase
{
    use RefreshDatabase;

    private function volunteer(): VolunteerProfile
    {
        $user = User::factory()->create();

        return VolunteerProfile::create([
            'user_id' => $user->id,
            'volunteer_code' => 'VOL-001',
            'is_active' => true,
        ]);
    }

    public function test_enrol_sets_the_pin_on_first_use_and_issues_a_scoped_token(): void
    {
        $volunteer = $this->volunteer();
        $token = 'test-enrolment-token';
        Cache::put("device-enrolment:{$token}", ['volunteer_profile_id' => $volunteer->id], now()->addMinutes(15));

        $response = $this->postJson('/api/scanner/v1/enrol', [
            'enrolment_token' => $token,
            'device_fingerprint' => 'fingerprint-1',
            'device_name' => 'Test Phone',
            'device_code' => 'DEV-01',
            'platform' => 'android',
            'pin' => '123456',
        ])->assertOk()->assertJsonStructure(['token', 'expires_at', 'device']);

        $this->assertNotNull($volunteer->fresh()->pin_hash);

        // The token is single-use.
        $this->postJson('/api/scanner/v1/enrol', [
            'enrolment_token' => $token,
            'device_fingerprint' => 'fingerprint-2',
            'device_name' => 'Test Phone 2',
            'device_code' => 'DEV-02',
            'platform' => 'android',
            'pin' => '123456',
        ])->assertStatus(422);

        $this->withToken($response->json('token'))
            ->getJson('/api/scanner/v1/manifest')
            ->assertOk();
    }

    public function test_enrol_rejects_the_wrong_pin_on_a_second_device(): void
    {
        $volunteer = $this->volunteer();
        $volunteer->forceFill(['pin_hash' => bcrypt('111111')])->save();

        $token = 'test-enrolment-token-2';
        Cache::put("device-enrolment:{$token}", ['volunteer_profile_id' => $volunteer->id], now()->addMinutes(15));

        $this->postJson('/api/scanner/v1/enrol', [
            'enrolment_token' => $token,
            'device_fingerprint' => 'fingerprint-3',
            'device_name' => 'Test Phone',
            'device_code' => 'DEV-03',
            'platform' => 'android',
            'pin' => '999999',
        ])->assertStatus(422);
    }

    public function test_enrol_rejects_an_unknown_token(): void
    {
        $this->postJson('/api/scanner/v1/enrol', [
            'enrolment_token' => 'does-not-exist',
            'device_fingerprint' => 'fingerprint-4',
            'device_name' => 'Test Phone',
            'device_code' => 'DEV-04',
            'platform' => 'android',
            'pin' => '123456',
        ])->assertStatus(422);
    }

    public function test_a_scanner_token_cannot_access_admin_routes(): void
    {
        $volunteer = $this->volunteer();
        $token = $volunteer->user->createToken('t', ['scanner'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/admin/auth/me')
            ->assertStatus(403);
    }
}
