<?php

namespace Tests\Feature\Admin;

use App\Domain\CheckIn\Models\CheckIn;
use App\Domain\CheckIn\Models\Gate;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\Ticket;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GateTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $eventManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole('Super Admin');

        $this->eventManager = User::factory()->create(['status' => 'active']);
        $this->eventManager->assignRole('Event Manager');
    }

    public function test_event_manager_can_list_and_view_gates_but_not_create_or_delete(): void
    {
        $gate = Gate::factory()->create(['code' => 'GATE-A']);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');

        $this->getJson(route('api.v1.admin.gates.index'))
            ->assertStatus(200)
            ->assertJsonFragment(['code' => 'GATE-A']);

        $this->getJson(route('api.v1.admin.gates.show', ['gate' => $gate->ulid]))
            ->assertStatus(200)
            ->assertJsonPath('data.code', 'GATE-A');

        $this->postJson(route('api.v1.admin.gates.store'), [
            'code' => 'GATE-B',
            'name' => 'Back Gate',
        ])->assertStatus(403);

        $this->deleteJson(route('api.v1.admin.gates.destroy', ['gate' => $gate->ulid]))
            ->assertStatus(403);
    }

    public function test_super_admin_can_create_update_and_delete_a_gate(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*'], 'web-admin');

        $createResponse = $this->postJson(route('api.v1.admin.gates.store'), [
            'code' => 'GATE-C',
            'name' => 'Main Gate',
            'is_active' => true,
        ]);

        $createResponse->assertStatus(201)->assertJsonPath('data.code', 'GATE-C');
        $gateUlid = $createResponse->json('data.ulid');

        $this->patchJson(route('api.v1.admin.gates.update', ['gate' => $gateUlid]), [
            'name' => 'Main Gate (Renamed)',
        ])->assertStatus(200)->assertJsonPath('data.name', 'Main Gate (Renamed)');

        $this->deleteJson(route('api.v1.admin.gates.destroy', ['gate' => $gateUlid]))->assertStatus(204);

        $this->assertDatabaseMissing('gates', ['ulid' => $gateUlid]);
    }

    public function test_gate_with_recorded_check_ins_cannot_be_deleted(): void
    {
        $gate = Gate::factory()->create();
        $ticket = Ticket::factory()->create();

        CheckIn::factory()->create([
            'gate_id' => $gate->id,
            'ticket_id' => $ticket->id,
        ]);

        Sanctum::actingAs($this->superAdmin, ['*'], 'web-admin');

        $response = $this->deleteJson(route('api.v1.admin.gates.destroy', ['gate' => $gate->ulid]));

        $response->assertStatus(422)->assertJsonPath('code', 'deletion_prevented');
        $this->assertDatabaseHas('gates', ['id' => $gate->id]);
    }

    public function test_gate_code_must_be_unique(): void
    {
        Gate::factory()->create(['code' => 'GATE-DUP']);

        Sanctum::actingAs($this->superAdmin, ['*'], 'web-admin');

        $this->postJson(route('api.v1.admin.gates.store'), [
            'code' => 'GATE-DUP',
            'name' => 'Duplicate Gate',
        ])->assertStatus(422);
    }
}
