<?php

namespace Tests\Feature\Rbac;

use App\Domain\CheckIn\Models\CheckIn;
use App\Domain\CheckIn\Models\Gate;
use App\Domain\CheckIn\Models\VolunteerProfile;
use App\Domain\Content\Models\ContentPage;
use App\Domain\Notification\Models\Notification;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2 exit criterion: every permission in config/rbac.php has a passing
 * allow-case and deny-case test (docs/08 §"Phase 2 exit criteria").
 *
 * Two layers of coverage:
 *  1. Every catalogued permission is exercised at the Gate level
 *     ($user->can()) — this covers permissions that don't yet have a wired
 *     HTTP endpoint.
 *  2. Every admin route that DOES exist gets a real HTTP allow/deny
 *     round-trip, to prove the permission is actually enforced in the
 *     controller/FormRequest and not just declared in the catalogue.
 *     Volunteer/device/user/role management endpoints have their own
 *     HTTP coverage in tests/Feature/Admin/{Volunteer,Device,UserRole}Test.php
 *     rather than duplicating it here.
 */
class ComprehensivePermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $eventManager;

    private User $volunteer;

    private User $noRoleUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole('Super Admin');

        $this->eventManager = User::factory()->create(['status' => 'active']);
        $this->eventManager->assignRole('Event Manager');

        $this->volunteer = User::factory()->create(['status' => 'active']);
        $this->volunteer->assignRole('Volunteer');

        $this->noRoleUser = User::factory()->create(['status' => 'active']);
    }

    // === Layer 1: every catalogued permission, allow-case and deny-case ===

    public function test_every_catalogued_permission_has_an_allow_case_and_a_deny_case(): void
    {
        foreach (config('rbac.permissions') as $permission) {
            $allowRole = $this->roleGrantingPermission($permission);

            $allowedUser = User::factory()->create(['status' => 'active']);
            $allowedUser->assignRole($allowRole);

            $this->assertTrue(
                $allowedUser->can($permission),
                "Expected role [{$allowRole}] to be granted [{$permission}]."
            );

            $this->assertFalse(
                $this->noRoleUser->can($permission),
                "Expected a user with no role to be denied [{$permission}]."
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function roleGrantingPermission(string $permission): string
    {
        foreach (config('rbac.roles') as $role => $permissions) {
            if ($role === 'Super Admin') {
                continue;
            }

            if (in_array($permission, $permissions, true)) {
                return $role;
            }
        }

        return 'Super Admin';
    }

    // === Layer 2: real HTTP round-trips against wired endpoints ===

    public function test_registration_view_any_http(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.registrations.index'))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.registrations.index'))->assertStatus(403);
    }

    public function test_registration_view_http(): void
    {
        $registration = Registration::factory()->create();

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.registrations.show', ['registration' => $registration->ulid]))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.registrations.show', ['registration' => $registration->ulid]))->assertStatus(403);
    }

    public function test_registration_delete_http(): void
    {
        $forSuperAdmin = Registration::factory()->create(['status' => 'draft']);
        Sanctum::actingAs($this->superAdmin, ['*'], 'web-admin');
        $this->deleteJson(route('api.v1.admin.registrations.destroy', ['registration' => $forSuperAdmin->ulid]))->assertStatus(204);

        $forEventManager = Registration::factory()->create(['status' => 'draft']);
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->deleteJson(route('api.v1.admin.registrations.destroy', ['registration' => $forEventManager->ulid]))->assertStatus(403);
    }

    public function test_attendee_view_any_http(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.attendees.index'))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.attendees.index'))->assertStatus(403);
    }

    public function test_attendee_view_http(): void
    {
        $attendee = Attendee::factory()->create();

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.attendees.show', ['attendee' => $attendee->ulid]))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.attendees.show', ['attendee' => $attendee->ulid]))->assertStatus(403);
    }

    public function test_attendee_delete_http(): void
    {
        $forSuperAdmin = Attendee::factory()->create();
        Sanctum::actingAs($this->superAdmin, ['*'], 'web-admin');
        $this->deleteJson(route('api.v1.admin.attendees.destroy', ['attendee' => $forSuperAdmin->ulid]))->assertStatus(204);

        $forEventManager = Attendee::factory()->create();
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->deleteJson(route('api.v1.admin.attendees.destroy', ['attendee' => $forEventManager->ulid]))->assertStatus(403);
    }

    public function test_payment_view_any_http(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.payments.index'))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.payments.index'))->assertStatus(403);
    }

    public function test_payment_view_http(): void
    {
        $payment = Payment::factory()->create();

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.payments.show', ['payment' => $payment->ulid]))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.payments.show', ['payment' => $payment->ulid]))->assertStatus(403);
    }

    public function test_payment_verify_manual_http(): void
    {
        $registration = Registration::factory()->create(['status' => 'pending_payment']);
        $payment = Payment::factory()->create([
            'registration_id' => $registration->id,
            'status' => 'awaiting_verification',
            'manual_trx_id' => 'TRX-PERM-TEST-001',
        ]);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.payments.verify-manual', ['payment' => $payment->ulid]), [
            'verification_note' => 'Verified',
        ])->assertStatus(403);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.payments.verify-manual', ['payment' => $payment->ulid]), [
            'verification_note' => 'Verified',
        ])->assertStatus(200);
    }

    public function test_payment_refund_http(): void
    {
        $payment = Payment::factory()->create(['status' => 'succeeded', 'amount_paid_paisa' => 100000]);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.payments.refund', ['payment' => $payment->ulid]), [
            'amount_paisa' => 50000,
            'reason' => 'Test refund',
            'type' => 'partial',
        ])->assertStatus(403);
    }

    public function test_ticket_view_any_http(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.tickets.index'))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.tickets.index'))->assertStatus(403);
    }

    public function test_ticket_view_http(): void
    {
        $ticket = Ticket::factory()->create();

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.tickets.show', ['ticket' => $ticket->ulid]))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.tickets.show', ['ticket' => $ticket->ulid]))->assertStatus(403);
    }

    public function test_ticket_void_http(): void
    {
        $forEventManager = Ticket::factory()->create(['status' => 'active']);
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.tickets.void', ['ticket' => $forEventManager->ulid]), [
            'void_reason' => 'Test void',
        ])->assertStatus(200);

        $forVolunteer = Ticket::factory()->create(['status' => 'active']);
        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.tickets.void', ['ticket' => $forVolunteer->ulid]), [
            'void_reason' => 'Test void',
        ])->assertStatus(403);
    }

    public function test_ticket_reissue_http(): void
    {
        $ticket = Ticket::factory()->create(['status' => 'active']);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.tickets.reissue', ['ticket' => $ticket->ulid]))->assertStatus(403);
    }

    public function test_ticket_type_view_any_http(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.ticket-types.index'))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.ticket-types.index'))->assertStatus(403);
    }

    public function test_ticket_type_manage_http(): void
    {
        // ticket_type.manage is deliberately Super-Admin-only in config/rbac.php —
        // Event Manager can view ticket types but not create/reprice them.
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.ticket-types.store'), [
            'name' => 'Test Type',
            'code' => 'TEST',
            'base_price_paisa' => 100000,
            'quantity_total' => 100,
        ])->assertStatus(403);

        Sanctum::actingAs($this->superAdmin, ['*'], 'web-admin');
        $response = $this->postJson(route('api.v1.admin.ticket-types.store'), [
            'name' => 'Test Type',
            'code' => 'TEST',
            'base_price_paisa' => 100000,
            'quantity_total' => 100,
        ]);
        $this->assertNotEquals(403, $response->status());
    }

    public function test_ticket_type_delete_http(): void
    {
        $forSuperAdmin = TicketType::factory()->create(['quantity_sold' => 0, 'quantity_reserved' => 0]);
        Sanctum::actingAs($this->superAdmin, ['*'], 'web-admin');
        $this->deleteJson(route('api.v1.admin.ticket-types.destroy', ['ticket_type' => $forSuperAdmin->ulid]))->assertStatus(204);

        $forEventManager = TicketType::factory()->create(['quantity_sold' => 0, 'quantity_reserved' => 0]);
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->deleteJson(route('api.v1.admin.ticket-types.destroy', ['ticket_type' => $forEventManager->ulid]))->assertStatus(403);
    }

    public function test_report_view_batch_breakdown_http(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.reports.show', ['reportKey' => 'registrations_by_batch']))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.reports.show', ['reportKey' => 'registrations_by_batch']))->assertStatus(403);
    }

    public function test_report_view_revenue_http(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.reports.show', ['reportKey' => 'revenue_summary']))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.reports.show', ['reportKey' => 'revenue_summary']))->assertStatus(403);
    }

    public function test_report_export_pdf_http(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.reports.export', ['reportKey' => 'registrations_by_batch']), [
            'format' => 'pdf',
        ])->assertStatus(202);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.reports.export', ['reportKey' => 'registrations_by_batch']), [
            'format' => 'pdf',
        ])->assertStatus(403);
    }

    public function test_settings_view_http(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.settings.index'))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.settings.index'))->assertStatus(403);
    }

    public function test_device_enrol_http(): void
    {
        $volunteerProfile = VolunteerProfile::factory()->create();

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.volunteers.enrolment-token', ['volunteer' => $volunteerProfile->ulid]))
            ->assertStatus(403);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.volunteers.enrolment-token', ['volunteer' => $volunteerProfile->ulid]))
            ->assertStatus(200);
    }

    public function test_gate_view_any_http(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.gates.index'))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.gates.index'))->assertStatus(403);
    }

    public function test_gate_view_http(): void
    {
        $gate = Gate::factory()->create();

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.gates.show', ['gate' => $gate->ulid]))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.gates.show', ['gate' => $gate->ulid]))->assertStatus(403);
    }

    public function test_gate_manage_http(): void
    {
        // gate.manage is Super-Admin-only, mirroring ticket_type.manage —
        // Event Manager can view gates but not create/edit them.
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.gates.store'), [
            'code' => 'PERM-TEST',
            'name' => 'Permission Test Gate',
        ])->assertStatus(403);

        Sanctum::actingAs($this->superAdmin, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.gates.store'), [
            'code' => 'PERM-TEST',
            'name' => 'Permission Test Gate',
        ])->assertStatus(201);

        $gate = Gate::factory()->create();

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->patchJson(route('api.v1.admin.gates.update', ['gate' => $gate->ulid]), [
            'name' => 'Renamed',
        ])->assertStatus(403);
    }

    public function test_gate_delete_http(): void
    {
        $forSuperAdmin = Gate::factory()->create();
        Sanctum::actingAs($this->superAdmin, ['*'], 'web-admin');
        $this->deleteJson(route('api.v1.admin.gates.destroy', ['gate' => $forSuperAdmin->ulid]))->assertStatus(204);

        $forEventManager = Gate::factory()->create();
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->deleteJson(route('api.v1.admin.gates.destroy', ['gate' => $forEventManager->ulid]))->assertStatus(403);
    }

    public function test_checkin_view_any_http(): void
    {
        // Volunteers already hold checkin.view_any (pre-existing grant, for
        // their own scanner-side sync/manifest use) so they are not the
        // deny-case here — use noRoleUser instead.
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.check-ins.index'))->assertStatus(200);

        Sanctum::actingAs($this->noRoleUser, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.check-ins.index'))->assertStatus(403);
    }

    public function test_checkin_view_http(): void
    {
        $checkIn = CheckIn::factory()->create();

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.check-ins.show', ['check_in' => $checkIn->ulid]))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.check-ins.show', ['check_in' => $checkIn->ulid]))->assertStatus(403);
    }

    public function test_checkin_manual_override_http(): void
    {
        $gate = Gate::factory()->create();
        $ticket = Ticket::factory()->create();

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.check-ins.manual-override'), [
            'ticket_ulid' => $ticket->ulid,
            'gate_ulid' => $gate->ulid,
            'party_size' => 1,
            'reason' => 'Permission test',
        ])->assertStatus(403);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.check-ins.manual-override'), [
            'ticket_ulid' => $ticket->ulid,
            'gate_ulid' => $gate->ulid,
            'party_size' => 1,
            'reason' => 'Permission test',
        ])->assertStatus(201);
    }

    public function test_checkin_resolve_conflict_http(): void
    {
        $checkIn = CheckIn::factory()->create(['conflict_flag' => true, 'conflict_resolved_at' => null]);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.check-ins.resolve-conflict', ['check_in' => $checkIn->ulid]))
            ->assertStatus(403);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.check-ins.resolve-conflict', ['check_in' => $checkIn->ulid]))
            ->assertStatus(200);
    }

    public function test_checkin_view_live_dashboard_http(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.check-ins.live-dashboard'))->assertStatus(200);

        Sanctum::actingAs($this->noRoleUser, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.check-ins.live-dashboard'))->assertStatus(403);
    }

    public function test_notification_view_any_http(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.notifications.index'))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.notifications.index'))->assertStatus(403);
    }

    public function test_notification_view_http(): void
    {
        $notification = Notification::factory()->create();

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.notifications.show', ['notification' => $notification->ulid]))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.notifications.show', ['notification' => $notification->ulid]))->assertStatus(403);
    }

    public function test_notification_resend_http(): void
    {
        $notification = Notification::factory()->create(['status' => 'failed']);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.notifications.resend', ['notification' => $notification->ulid]))->assertStatus(403);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.notifications.resend', ['notification' => $notification->ulid]))->assertStatus(200);
    }

    public function test_notification_view_costs_http(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.notifications.costs'))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.notifications.costs'))->assertStatus(403);
    }

    public function test_notification_send_broadcast_http(): void
    {
        EventSetting::factory()->create(['key' => 'notification.sms_enabled', 'group' => 'notification', 'type' => 'bool', 'value' => '1']);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.notifications.kill-switches'))->assertStatus(403);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.notifications.kill-switches'))->assertStatus(200);
        $this->patchJson(route('api.v1.admin.notifications.kill-switches.update'), [
            'channel' => 'sms',
            'enabled' => false,
        ])->assertStatus(200);
    }

    public function test_notification_manage_templates_http(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.notifications.templates'))->assertStatus(403);

        Sanctum::actingAs($this->superAdmin, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.notifications.templates'))->assertStatus(200);
    }

    // === CMS (Phase 3.5, admin half) ===

    public function test_content_view_any_http(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.content.pages.index'))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.content.pages.index'))->assertStatus(403);
    }

    public function test_content_view_http(): void
    {
        $page = ContentPage::factory()->create();

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.content.pages.show', ['page' => $page->ulid]))->assertStatus(200);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.content.pages.show', ['page' => $page->ulid]))->assertStatus(403);
    }

    public function test_content_create_http(): void
    {
        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.content.pages.store'), ['slug' => 'denied', 'title' => 'Denied'])
            ->assertStatus(403);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.content.pages.store'), ['slug' => 'allowed', 'title' => 'Allowed'])
            ->assertStatus(201);
    }

    public function test_content_update_http(): void
    {
        $page = ContentPage::factory()->create();

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->patchJson(route('api.v1.admin.content.pages.update', ['page' => $page->ulid]), ['title' => 'Denied'])
            ->assertStatus(403);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->patchJson(route('api.v1.admin.content.pages.update', ['page' => $page->ulid]), ['title' => 'Allowed'])
            ->assertStatus(200);
    }

    public function test_content_publish_http(): void
    {
        $page = ContentPage::factory()->create();

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.content.pages.status', ['page' => $page->ulid]), ['status' => 'published'])
            ->assertStatus(403);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->postJson(route('api.v1.admin.content.pages.status', ['page' => $page->ulid]), ['status' => 'published'])
            ->assertStatus(200);
    }

    public function test_content_delete_http(): void
    {
        $page = ContentPage::factory()->create();

        // Deleting content follows the same Super-Admin-only rule as every
        // other `*.delete` — an Event Manager may publish but not destroy.
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->deleteJson(route('api.v1.admin.content.pages.destroy', ['page' => $page->ulid]))->assertStatus(403);

        Sanctum::actingAs($this->superAdmin, ['*'], 'web-admin');
        $this->deleteJson(route('api.v1.admin.content.pages.destroy', ['page' => $page->ulid]))->assertStatus(204);
    }

    public function test_content_manage_media_http(): void
    {
        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.content.media.index'))->assertStatus(403);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $this->getJson(route('api.v1.admin.content.media.index'))->assertStatus(200);
    }
}
