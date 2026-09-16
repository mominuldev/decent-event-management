<?php

namespace Tests\Feature\Ticketing;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Actions\IssueTicket;
use App\Domain\Ticketing\Models\TicketType;
use App\Domain\Ticketing\Services\TicketShareCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one share-card test that pays for Chrome: proves the frame really
 * lays out at its designed size, at 2×, and paints its background — the
 * things a faked renderer cannot vouch for. Everything about the card's
 * *content* is covered without Chrome in {@see TicketShareCardTest}.
 */
class TicketShareCardRenderTest extends TestCase
{
    use RefreshDatabase;

    protected bool $rendersRealImages = true;

    public function test_chrome_renders_the_card_at_the_designed_size(): void
    {
        $ticketType = TicketType::factory()->create();
        $attendee = Attendee::factory()->create(['full_name_bn' => 'মোহাম্মদ রহিম উদ্দিন', 'ssc_batch_year' => 1998]);
        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'paid',
        ]);
        $ticket = app(IssueTicket::class)->execute($registration)->fresh();

        $jpeg = app(TicketShareCard::class)->render($ticket);

        $image = imagecreatefromstring($jpeg);
        $this->assertNotFalse($image);
        $this->assertSame(TicketShareCard::WIDTH * TicketShareCard::SCALE, imagesx($image));
        $this->assertSame(TicketShareCard::HEIGHT * TicketShareCard::SCALE, imagesy($image));

        // The corner is the navy backdrop, not an unpainted white canvas.
        $corner = imagecolorsforindex($image, imagecolorat($image, 4, 4));
        $this->assertLessThan(60, $corner['red']);
        $this->assertLessThan(60, $corner['green']);
        $this->assertGreaterThan($corner['red'], $corner['blue']);

        // And the ticket paper is really there, near-white, where the frame puts it.
        $paper = imagecolorsforindex($image, imagecolorat($image, 700, 700));
        $this->assertGreaterThan(235, $paper['red']);
        $this->assertGreaterThan(235, $paper['green']);
        $this->assertGreaterThan(235, $paper['blue']);

        $this->assertGreaterThan(50_000, strlen($jpeg));
    }
}
