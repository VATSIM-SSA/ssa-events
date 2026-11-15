<?php

namespace Tests\Feature;

use App\Auth\Roles;
use App\Enums\AssignmentSource;
use App\Enums\ControllerRating;
use App\Enums\IntegrationRunStatus;
use App\Integrations\ControlCenter\AreaMap;
use App\Integrations\ControlCenter\Coverage;
use App\Integrations\ControlCenter\Member;
use App\Integrations\RunSync;
use App\Models\Event;
use App\Models\Fir;
use App\Models\IntegrationRun;
use App\Models\IntegrationSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * VATSSA's fork: one roster for the whole division (config/vatssa.php). Active in any
 * Control Center area, or a visiting endorsement, gives one Controller role across the hub.
 */
class VatssaHubWideRosterSyncTest extends TestCase
{
    use RefreshDatabase;

    private Fir $fasa;

    private Fir $htdc;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'vacc.subdivision' => null,
            'integrations.settings.control-center' => [],
            'vatssa.hub_wide' => true,
        ]);
        $this->fasa = Fir::factory()->create(['icao' => 'FASA', 'name' => 'South Africa']);
        $this->htdc = Fir::factory()->create(['icao' => 'HTDC', 'name' => 'Dar es Salaam']);
    }

    public function test_active_in_one_area_gives_controller_across_the_hub_and_booking_in_every_fir(): void
    {
        $this->switchOn();
        $member = User::factory()->rated(ControllerRating::S2)->create(['vatsim_cid' => 1000001]);
        $this->fakeControlCenter([$this->member(1000001, active: ['southern'])]);

        $this->sync();

        $this->assertSame([null], $this->automaticFirs($member));

        $this->travelTo(Carbon::parse('2026-11-01 12:00', 'UTC'));
        foreach ([10 => $this->fasa, 11 => $this->htdc] as $day => $fir) {
            $event = Event::factory()->withShifts()->create([
                'fir_id' => $fir->id,
                'starts_at' => Carbon::parse("2026-11-{$day} 16:00", 'UTC'),
                'ends_at' => Carbon::parse("2026-11-{$day} 20:00", 'UTC'),
            ]);
            $shift = $event->positions()->create(['callsign' => "{$fir->icao}_TWR"])->shifts()->create(['starts_offset' => 0, 'ends_offset' => 120]);

            $this->actingAs($member->fresh())->post("/occurrences/{$event->occurrences()->sole()->id}/shifts/{$shift->id}/booking")
                ->assertSessionHasNoErrors();
        }
    }

    public function test_active_in_several_areas_still_gives_one_role(): void
    {
        $this->switchOn();
        $member = User::factory()->create(['vatsim_cid' => 1000001]);
        $this->fakeControlCenter([$this->member(1000001, active: ['southern', 'central', 'western'])]);

        $this->sync();

        $this->assertSame([null], $this->automaticFirs($member));
        $this->assertSame(1, $this->lastRun()->added);
    }

    public function test_a_visiting_endorsement_gives_controller_across_the_hub(): void
    {
        $this->switchOn();
        $visitor = User::factory()->create(['vatsim_cid' => 1000001]);
        $this->fakeControlCenter([
            $this->member(1000001, endorsements: ['visiting' => [['rating' => 'S3', 'areas' => ['Western'], 'valid_from' => null, 'valid_to' => null]]]),
            $this->member(1000002, active: ['southern']),
        ]);

        $this->sync();

        $this->assertSame([null], $this->automaticFirs($visitor));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function endorsementsThatGiveNothing(): array
    {
        return [
            'solo' => [['solo' => [['positions' => ['FAOR_APP'], 'valid_from' => null, 'valid_to' => null]]]],
            'facility' => [['facility' => [['rating' => 'MAE FAOR TWR', 'valid_from' => null, 'valid_to' => null]]]],
            'examiner' => [['examiner' => [['rating' => 'S3', 'areas' => ['Southern'], 'valid_from' => null, 'valid_to' => null]]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $endorsements
     */
    #[DataProvider('endorsementsThatGiveNothing')]
    public function test_other_endorsements_alone_give_nothing(array $endorsements): void
    {
        $this->switchOn();
        $student = User::factory()->create(['vatsim_cid' => 1000001]);
        $this->fakeControlCenter([$this->member(1000001, endorsements: $endorsements), $this->member(1000002, active: ['southern'])]);

        $this->sync();

        $this->assertSame([], $this->automaticFirs($student));
    }

    public function test_leavers_lose_it_manual_roles_stay_and_old_fir_scoped_ones_are_replaced(): void
    {
        $this->switchOn();
        $leaver = User::factory()->create(['vatsim_cid' => 1000001]);
        $leaver->assignRole(Roles::CONTROLLER, null, AssignmentSource::Automatic);
        $leaver->assignRole(Roles::CONTROLLER, $this->htdc, AssignmentSource::Manual);
        $staying = User::factory()->create(['vatsim_cid' => 1000002]);
        $staying->assignRole(Roles::CONTROLLER, $this->fasa, AssignmentSource::Automatic);
        $this->fakeControlCenter([$this->member(1000001), $this->member(1000002, active: ['southern'])], removalLimit: 100);

        $this->sync();

        $this->assertSame([], $this->automaticFirs($leaver));
        $this->assertTrue($leaver->roleAssignments()->where('fir_id', $this->htdc->id)->where('source', AssignmentSource::Manual)->exists());
        $this->assertSame([null], $this->automaticFirs($staying));
        $this->assertSame([1, 2], [$this->lastRun()->added, $this->lastRun()->removed]);
    }

    public function test_the_next_sync_leaves_a_held_hub_wide_role_alone(): void
    {
        $this->switchOn();
        $member = User::factory()->create(['vatsim_cid' => 1000001]);
        $this->fakeControlCenter([$this->member(1000001, active: ['southern'])]);

        $this->sync();
        $this->sync();

        $this->assertSame([null], $this->automaticFirs($member));
        $this->assertSame(IntegrationRunStatus::Succeeded, $this->lastRun()->status);
        $this->assertSame([0, 0], [$this->lastRun()->added, $this->lastRun()->removed]);
    }

    public function test_it_needs_no_area_map_and_warns_about_nothing(): void
    {
        $this->switchOn(areas: '');
        $controller = User::factory()->create(['vatsim_cid' => 1000001]);
        $this->fakeControlCenter([$this->member(1000001, active: ['eastern'], inactive: ['southern', 'vanilla'])]);

        $this->sync();

        $this->assertSame([null], $this->automaticFirs($controller));
        $this->actingAs(User::factory()->admin()->create())->get('/admin/integrations/control-center')->assertInertia(fn (Assert $page) => $page
            ->where('runs.0.status', 'succeeded')
            ->where('runs.0.warnings', [])
        );
    }

    public function test_coverage_counts_any_area_and_still_checks_the_rating(): void
    {
        $coverage = new Coverage(AreaMap::parse(''));
        $s2 = new Member(['cid' => 1000001, 'rating' => 'S2', 'active_areas' => ['western'], 'endorsements' => []]);
        $inactive = new Member(['cid' => 1000002, 'rating' => 'C1', 'active_areas' => [], 'endorsements' => []]);
        $visitor = new Member(['cid' => 1000003, 'rating' => 'C1', 'active_areas' => [], 'endorsements' => [
            'visiting' => [['rating' => 'S2', 'areas' => ['Southern']]],
        ]]);

        $this->assertNull($coverage->problem($s2, 'FASA_TWR', 'FASA'));
        $this->assertSame('Needs S3; rated S2 in Control Center.', $coverage->problem($s2, 'FAOR_APP', 'FASA'));
        $this->assertSame('Not active in Control Center, and no visiting endorsement.', $coverage->problem($inactive, 'HTDC_CTR', 'HTDC'));
        $this->assertNull($coverage->problem($visitor, 'HTDC_TWR', 'HTDC'));
        $this->assertSame('Needs S3; visiting endorsement for S2 only.', $coverage->problem($visitor, 'HTDC_APP', 'HTDC'));
    }

    public function test_off_keeps_upstreams_per_fir_roles(): void
    {
        config(['vatssa.hub_wide' => false]);
        $this->switchOn(areas: 'southern = FASA');
        $member = User::factory()->create(['vatsim_cid' => 1000001]);
        $this->fakeControlCenter([$this->member(1000001, active: ['southern'])]);

        $this->sync();

        $this->assertSame([$this->fasa->id], $this->automaticFirs($member));
    }

    private function switchOn(string $areas = '', int $removalLimit = 25): void
    {
        IntegrationSetting::query()->updateOrCreate(['key' => 'control-center'], [
            'enabled' => true,
            'features' => ['roster'],
            'settings' => ['url' => 'https://cc.example', 'token' => 'cc-key', 'areas' => $areas],
            'removal_limit' => $removalLimit,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $members
     */
    private function fakeControlCenter(array $members, ?int $removalLimit = null): void
    {
        if ($removalLimit !== null) {
            IntegrationSetting::query()->whereKey('control-center')->update(['removal_limit' => $removalLimit]);
        }

        Http::fake(['cc.example/api/v1/users*' => Http::response(['data' => $members])]);
    }

    /**
     * @param  list<string>  $active
     * @param  list<string>|null  $inactive  Defaults to VATSSA's other areas.
     * @param  array<string, mixed>  $endorsements
     * @return array<string, mixed>
     */
    private function member(int $cid, array $active = [], ?array $inactive = null, array $endorsements = []): array
    {
        $inactive ??= array_values(array_diff(['southern', 'central', 'western'], $active));

        return [
            'id' => $cid,
            'rating' => 'S2',
            'atc_active' => $active !== [],
            'atc_active_areas' => [...array_fill_keys($active, true), ...array_fill_keys($inactive, false)],
            'endorsements' => [...['visiting' => null, 'examiner' => null, 'solo' => null, 'facility' => null], ...$endorsements],
        ];
    }

    private function sync(?int $approvedBy = null): void
    {
        app()->call([new RunSync('control-center', 'roster', $approvedBy), 'handle']);
    }

    private function lastRun(): IntegrationRun
    {
        return IntegrationRun::query()->latest('id')->firstOrFail();
    }

    /**
     * @return list<int|null>
     */
    private function automaticFirs(User $user): array
    {
        return $user->roleAssignments()
            ->where('role_id', Role::findByName(Roles::CONTROLLER)->id)
            ->where('source', AssignmentSource::Automatic)
            ->pluck('fir_id')
            ->all();
    }
}
