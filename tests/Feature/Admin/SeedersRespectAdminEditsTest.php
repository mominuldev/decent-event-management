<?php

namespace Tests\Feature\Admin;

use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\EventSettingSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\TicketTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Re-running a seeder must never undo a decision somebody made in the
 * admin console.
 *
 * This is one rule across three seeders rather than three unrelated ones,
 * which is why it lives in one file: the `code`/`key` identifies the row,
 * everything a person can edit is seeded only when that row does not exist
 * yet. It is easy to get wrong the moment a new seeder is written, and the
 * failure is silent — a price, a message or a cutoff quietly reverts on
 * the next deploy and nothing reports it.
 */
class SeedersRespectAdminEditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseeding_does_not_revert_an_edited_ticket_price(): void
    {
        $this->seed(TicketTypeSeeder::class);

        $cen = TicketType::query()->where('code', 'CEN')->sole();
        $cen->update([
            'base_price_paisa' => 300000,
            'current_student_price_paisa' => 75000,
            'quantity_total' => 15000,
            'name' => 'Centennial Ticket (Gold)',
        ]);

        $this->seed(TicketTypeSeeder::class);

        $cen->refresh();

        // The money is the part that matters. `updateOrCreate` would have
        // repriced a ticket that may already have sold, and the post-sale
        // lock in TicketTypeController::update() would not have caught it —
        // that lock guards the HTTP path, and a seeder does not use it.
        $this->assertSame(300000, $cen->base_price_paisa);
        $this->assertSame(75000, $cen->current_student_price_paisa);
        $this->assertSame(15000, $cen->quantity_total);
        $this->assertSame('Centennial Ticket (Gold)', $cen->name);
    }

    public function test_reseeding_does_not_reopen_a_ticket_type_that_was_switched_off(): void
    {
        $this->seed(TicketTypeSeeder::class);

        TicketType::query()->where('code', 'CEN')->update(['is_active' => false, 'is_public' => false]);

        $this->seed(TicketTypeSeeder::class);

        // Putting a sold-out or withdrawn ticket back on sale during a
        // release is the kind of thing nobody notices until money arrives
        // for it.
        $cen = TicketType::query()->where('code', 'CEN')->sole();
        $this->assertFalse($cen->is_active);
        $this->assertFalse($cen->is_public);
    }

    public function test_reseeding_still_creates_a_ticket_type_that_is_genuinely_absent(): void
    {
        $this->seed(TicketTypeSeeder::class);
        $before = TicketType::query()->count();

        TicketType::query()->where('code', 'STU')->forceDelete();

        // Protecting edits must not turn a seeder into a no-op: a type
        // added in code still has to arrive.
        $this->seed(TicketTypeSeeder::class);

        $this->assertSame($before, TicketType::query()->count());
        $this->assertNotNull(TicketType::query()->where('code', 'STU')->first());
    }

    public function test_reseeding_neither_resurrects_nor_crashes_on_a_deleted_ticket_type(): void
    {
        $this->seed(TicketTypeSeeder::class);

        TicketType::query()->where('code', 'STU')->delete();

        // Two failures in one: `ticket_types.code` is unique across
        // soft-deleted rows, so a lookup that skips them tries to insert a
        // second STU and dies on a duplicate key — which `updateOrCreate`
        // did too, so this is a pre-existing hole rather than a new one.
        // And withdrawing a ticket type is a decision, so the seeder must
        // not put it back on sale either.
        $this->seed(TicketTypeSeeder::class);

        $this->assertNull(TicketType::query()->where('code', 'STU')->first());
        $this->assertSame(1, TicketType::withTrashed()->where('code', 'STU')->count());
    }

    public function test_reseeding_still_backfills_a_missing_sale_window(): void
    {
        $this->seed(TicketTypeSeeder::class);

        // A null sale_starts_at makes a type invisible to the public
        // endpoint however active it is, because SQL's NULL comparison is
        // not true. That is a bug being repaired, not a decision being
        // overridden, so this gap-fill survives the create-only rule.
        TicketType::query()->where('code', 'CEN')->update(['sale_starts_at' => null]);

        $this->seed(TicketTypeSeeder::class);

        $this->assertNotNull(TicketType::query()->where('code', 'CEN')->sole()->sale_starts_at);
    }

    public function test_reseeding_does_not_drag_an_admin_chosen_opening_date_forward(): void
    {
        $this->seed(TicketTypeSeeder::class);

        $opensAt = now()->addMonth()->startOfDay();
        TicketType::query()->where('code', 'CEN')->update(['sale_starts_at' => $opensAt]);

        $this->seed(TicketTypeSeeder::class);

        $this->assertTrue($opensAt->equalTo(TicketType::query()->where('code', 'CEN')->sole()->sale_starts_at));
    }

    public function test_reseeding_does_not_revert_an_edited_setting_or_template(): void
    {
        $this->seed(EventSettingSeeder::class);
        $this->seed(NotificationTemplateSeeder::class);

        EventSetting::query()->where('key', 'registration.max_family_size')->update(['value' => '9']);
        NotificationTemplate::query()
            ->where('key', 'ticket_delivered')->where('channel', 'sms')->where('locale', 'en')
            ->update(['body' => 'Edited wording.']);

        $this->seed(EventSettingSeeder::class);
        $this->seed(NotificationTemplateSeeder::class);

        $this->assertSame('9', EventSetting::query()->where('key', 'registration.max_family_size')->sole()->value);
        $this->assertSame(
            'Edited wording.',
            NotificationTemplate::query()
                ->where('key', 'ticket_delivered')->where('channel', 'sms')->where('locale', 'en')->sole()->body,
        );
    }
}
