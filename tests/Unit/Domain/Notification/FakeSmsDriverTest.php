<?php

namespace Tests\Unit\Domain\Notification;

use App\Domain\Notification\Channels\Contracts\ChannelSendResult;
use App\Domain\Notification\Channels\FakeSmsDriver;
use App\Domain\Notification\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FakeSmsDriverTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeSmsDriver;
    }

    public function test_send_returns_sent_with_segment_and_cost(): void
    {
        $notification = Notification::factory()->create([
            'channel' => 'sms',
            'recipient' => '8801700000001',
            'body_rendered' => str_repeat('A', 150),
        ]);

        $result = $this->driver->send($notification);

        $this->assertInstanceOf(ChannelSendResult::class, $result);
        $this->assertEquals(ChannelSendResult::STATUS_SENT, $result->status);
        $this->assertNotEmpty($result->providerMessageId);
        $this->assertEquals(1, $result->segmentCount);
        $this->assertEquals(50, $result->costPaisa);
    }

    public function test_send_calculates_multiple_segments_for_long_body(): void
    {
        $notification = Notification::factory()->create([
            'channel' => 'sms',
            'recipient' => '8801700000001',
            'body_rendered' => str_repeat('A', 200),
        ]);

        $result = $this->driver->send($notification);

        $this->assertEquals(2, $result->segmentCount);
        $this->assertEquals(100, $result->costPaisa);
    }

    public function test_send_returns_failed_when_recipient_matches_failure_trigger(): void
    {
        $notification = Notification::factory()->create([
            'channel' => 'sms',
            'recipient' => FakeSmsDriver::FAILURE_TRIGGER_RECIPIENT,
            'body_rendered' => 'Failed test',
        ]);

        $result = $this->driver->send($notification);

        $this->assertEquals(ChannelSendResult::STATUS_FAILED, $result->status);
        $this->assertNull($result->providerMessageId);
        $this->assertEquals('simulated_gateway_failure', $result->errorMessage);
    }
}
