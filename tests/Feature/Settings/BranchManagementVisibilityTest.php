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
