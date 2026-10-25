<?php

namespace Tests\Feature\Settings;

use App\Enums\AccessRole;
use App\Enums\StationDepartment;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BranchManagementVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_01_user_can_view_all_branches(): void
    {
        $branch01 = $this->createBranch('01', 'หนองบัวลำภู');
        $branch02 = $this->createBranch('02', 'ขอนแก่น');

        $user = $this->createAdminSystemUser($branch01, AccessRole::Owner);

        $response = $this->actingAs($user)->get(route('settings.data.branches.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/data/branches/index')
            ->has('branches', 2)
            ->where('branches.0.id', $branch02->id)
            ->where('branches.1.id', $branch01->id)
        );
    }

    public function test_non_branch_01_user_sees_only_own_branch(): void
    {
        $branch01 = $this->createBranch('01', 'หนองบัวลำภู');
        $branch02 = $this->createBranch('02', 'ขอนแก่น');

        $user = $this->createAdminSystemUser($branch02, AccessRole::AdminSystem);

        $response = $this->actingAs($user)->get(route('settings.data.branches.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/data/branches/index')
            ->has('branches', 1)
            ->where('branches.0.id', $branch02->id)
        );

        $this->assertNotSame($branch01->id, $branch02->id);
    }

    public function test_non_branch_01_user_cannot_update_other_branch(): void
    {
        $branch01 = $this->createBranch('01', 'หนองบัวลำภู');
        $branch02 = $this->createBranch('02', 'ขอนแก่น');

        $user = $this->createAdminSystemUser($branch02, AccessRole::AdminSystem);

        $response = $this->actingAs($user)->put(route('settings.data.branches.update', $branch01), [
            'branch_code' => '01',
            'branch_name' => 'แก้ไขไม่ได้',
            'phone' => '',
            'address' => '',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('branches', [
            'id' => $branch01->id,
            'branch_name' => 'หนองบัวลำภู',
        ]);
    }

    public function test_non_branch_01_user_cannot_create_new_branch(): void
    {
        $branch02 = $this->createBranch('02', 'ขอนแก่น');
        $user = $this->createAdminSystemUser($branch02, AccessRole::AdminSystem);

        $response = $this->actingAs($user)->post(route('settings.data.branches.store'), [
            'branch_code' => '03',
            'branch_name' => 'สาขาใหม่',
            'phone' => '',
            'address' => '',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('branches', [
            'branch_code' => '03',
        ]);
    }

    public function test_a_branch_is_created_and_edited_with_its_header_colour(): void
    {
        $branch01 = $this->createBranch('01', 'หนองบัวลำภู');
        $user = $this->createAdminSystemUser($branch01, AccessRole::Owner);

        $this->actingAs($user)->post(route('settings.data.branches.store'), [
            'branch_code' => '05', 'branch_name' => 'อุดร', 'header_color' => '#b91c1c',
        ])->assertSessionHasNoErrors();

        $branch = Branch::query()->where('branch_code', '05')->sole();
        $this->assertSame('#B91C1C', $branch->header_color);

        $this->actingAs($user)->put(route('settings.data.branches.update', $branch), [
            'branch_code' => '05', 'branch_name' => 'อุดร', 'header_color' => '#166534',
        ])->assertSessionHasNoErrors();

        $this->assertSame('#166534', $branch->fresh()->header_color);

        $this->actingAs($user)->get(route('settings.data.branches.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('branches', fn ($branches): bool => collect($branches)
                    ->firstWhere('branch_code', '05')['header_color'] === '#166534'));
    }

    public function test_a_header_colour_that_is_not_a_colour_is_refused(): void
    {
        $branch01 = $this->createBranch('01', 'หนองบัวลำภู');
        $user = $this->createAdminSystemUser($branch01, AccessRole::Owner);

        $this->actingAs($user)->post(route('settings.data.branches.store'), [
            'branch_code' => '06', 'branch_name' => 'เลย', 'header_color' => 'red;}',
        ])->assertSessionHasErrors('header_color');

        $this->assertFalse(Branch::query()->where('branch_code', '06')->exists());
    }

    public function test_a_branch_saved_without_a_colour_keeps_the_default(): void
    {
        $branch01 = $this->createBranch('01', 'หนองบัวลำภู');
        $user = $this->createAdminSystemUser($branch01, AccessRole::Owner);

        $this->actingAs($user)->post(route('settings.data.branches.store'), [
            'branch_code' => '07', 'branch_name' => 'ชัยภูมิ',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Branch::query()->where('branch_code', '07')->sole()->header_color);
    }

    private function createBranch(string $code, string $name): Branch
    {
        return Branch::query()->create([
            'branch_code' => $code,
            'branch_name' => $name,
        ]);
    }

    private function createAdminSystemUser(Branch $branch, AccessRole $accessRole): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'station_department' => StationDepartment::None,
            'access_role' => $accessRole,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }
}
