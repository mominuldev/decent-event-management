<?php

namespace Tests\Unit\Domain\Notification;

use App\Domain\Notification\Channels\Contracts\ChannelSendResult;
use App\Domain\Notification\Channels\FakeWhatsAppDriver;
use App\Domain\Notification\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FakeWhatsAppDriverTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsAppDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeWhatsAppDriver;
    }

    public function test_send_returns_sent_with_message_id_and_cost(): void
    {
        $notification = Notification::factory()->create([
            'channel' => 'whatsapp',
            'recipient' => '8801700000001',
            'body_rendered' => 'Test WhatsApp message',
        ]);

        $result = $this->driver->send($notification);

        $this->assertInstanceOf(ChannelSendResult::class, $result);
        $this->assertEquals(ChannelSendResult::STATUS_SENT, $result->status);
        $this->assertNotEmpty($result->providerMessageId);
        $this->assertStringContainsString('FAKE-WA-', $result->providerMessageId);
        $this->assertEquals(100, $result->costPaisa);
    }

    public function test_send_returns_failed_when_recipient_matches_failure_trigger(): void
    {
        $notification = Notification::factory()->create([
            'channel' => 'whatsapp',
            'recipient' => FakeWhatsAppDriver::FAILURE_TRIGGER_RECIPIENT,
            'body_rendered' => 'Failed test',
        ]);

        $result = $this->driver->send($notification);

        $this->assertEquals(ChannelSendResult::STATUS_FAILED, $result->status);
        $this->assertNull($result->providerMessageId);
        $this->assertEquals('simulated_delivery_failure', $result->errorMessage);
    }
}
