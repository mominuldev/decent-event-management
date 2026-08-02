<?php

namespace Tests\Unit\Domain\Notification;

use App\Domain\Notification\Channels\Contracts\ChannelSendResult;
use App\Domain\Notification\Channels\FakeEmailDriver;
use App\Domain\Notification\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FakeEmailDriverTest extends TestCase
{
    use RefreshDatabase;

    private FakeEmailDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeEmailDriver;
    }

    public function test_send_returns_sent_with_message_id(): void
    {
        $notification = Notification::factory()->create([
            'recipient' => 'test@example.com',
            'body_rendered' => 'Test email body',
        ]);

        $result = $this->driver->send($notification);

        $this->assertInstanceOf(ChannelSendResult::class, $result);
        $this->assertEquals(ChannelSendResult::STATUS_SENT, $result->status);
        $this->assertNotEmpty($result->providerMessageId);
        $this->assertStringContainsString('FAKE-EMAIL-', $result->providerMessageId);
        $this->assertNull($result->errorMessage);
    }

    public function test_send_returns_failed_when_recipient_matches_failure_trigger(): void
    {
        $notification = Notification::factory()->create([
            'recipient' => FakeEmailDriver::FAILURE_TRIGGER_RECIPIENT,
            'body_rendered' => 'Bounce test',
        ]);

        $result = $this->driver->send($notification);

        $this->assertEquals(ChannelSendResult::STATUS_FAILED, $result->status);
        $this->assertNull($result->providerMessageId);
        $this->assertEquals('simulated_bounce', $result->errorMessage);
    }
}
