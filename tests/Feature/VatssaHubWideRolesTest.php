<?php

namespace Tests\Feature;

use App\Auth\Roles;
use App\Models\Fir;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * VATSSA's fork: every role applies across the hub (config/vatssa.php).
 */
class VatssaHubWideRolesTest extends TestCase
{
    use RefreshDatabase;

    private Fir $fasa;

    protected function setUp(): void
    {
        parent::setUp();

        config(['vatssa.hub_wide' => true]);
        $this->fasa = Fir::factory()->create(['icao' => 'FASA', 'name' => 'South Africa']);
    }

    public function test_production_has_it_on_unless_switched_off(): void
    {
        // phpunit.xml switches it off for upstream's tests, so check the default the server gets.
        $this->assertStringContainsString("env('VATSSA_HUB_WIDE', true)", file_get_contents(config_path('vatssa.php')));
    }

    public function test_every_role_is_offered_across_the_hub_only(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        Role::query()->create(['name' => 'FIR Lead', 'permissions' => []]);

        $this->actingAs($admin)->get("/admin/users/{$user->id}")->assertInertia(fn (Assert $page) => $page
            ->where('roles', fn ($roles) => collect($roles)->isNotEmpty()
                && collect($roles)->every(fn ($role) => $role['grantableIn'] === [null]))
        );
    }

    public function test_a_role_cannot_be_given_in_one_fir(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['name' => 'Kim']);

        $this->actingAs($admin)->post("/admin/users/{$user->id}/roles", ['role_id' => Role::findByName(Roles::EVENT_STAFF)->id, 'fir_id' => $this->fasa->id])
            ->assertSessionHasErrors(['roles' => 'Every role applies across the hub, so it can’t be given in one FIR.']);

        $this->assertSame(0, $user->roleAssignments()->count());
    }

    public function test_a_role_can_be_given_across_the_hub(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['name' => 'Kim']);

        $this->actingAs($admin)->post("/admin/users/{$user->id}/roles", ['role_id' => Role::findByName(Roles::VACC_STAFF)->id, 'fir_id' => null])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Division Staff'], Roles::labelsFor($user->fresh())->all());
    }

    public function test_a_fir_scoped_role_left_from_before_can_still_be_taken_away(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['name' => 'Kim']);
        $assignment = $user->assignRole(Roles::EVENT_STAFF, $this->fasa);

        $this->actingAs($admin)->delete("/admin/users/{$user->id}/roles/{$assignment->id}")->assertSessionHasNoErrors();

        $this->assertSame(0, $user->roleAssignments()->count());
    }
}
