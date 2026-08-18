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

class UserManagementUatTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_in_branch_01_can_access_user_management_and_filter_other_branch(): void
    {
        $branch01 = $this->createBranch('01', 'Nong Bua Lamphu');
        $branch02 = $this->createBranch('02', 'Khon Kaen');

        $owner = $this->createUserForBranch($branch01, AccessRole::Owner, UserRole::Admin);
        $this->createUserForBranch($branch01, AccessRole::Counter, UserRole::Sales);
        $this->createUserForBranch($branch02, AccessRole::Counter, UserRole::Sales);

        $response = $this->actingAs($owner)->get(route('settings.users.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/users/index')
            ->has('users.data', 3)
            ->has('branches', 2)
        );

        $branchFilterResponse = $this->actingAs($owner)->get(route('settings.users.index', [
            'branch_id' => (string) $branch02->id,
        ]));

        $branchFilterResponse->assertOk();
        $branchFilterResponse->assertInertia(fn (Assert $page) => $page
            ->component('settings/users/index')
            ->has('users.data', 1)
            ->where('users.data.0.branch_id', $branch02->id)
        );
    }

    public function test_admin_system_in_branch_01_can_view_other_branch_users(): void
    {
        $branch01 = $this->createBranch('01', 'Nong Bua Lamphu');
        $branch02 = $this->createBranch('02', 'Udonthani');

        $adminSystem = $this->createUserForBranch($branch01, AccessRole::AdminSystem, UserRole::Admin);
        $this->createUserForBranch($branch02, AccessRole::Counter, UserRole::Sales);

        $response = $this->actingAs($adminSystem)->get(route('settings.users.index', [
            'branch_id' => (string) $branch02->id,
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/users/index')
            ->has('users.data', 1)
            ->where('users.data.0.branch_id', $branch02->id)
        );
    }

    public function test_admin_system_outside_branch_01_cannot_filter_into_other_branch(): void
    {
        $branch01 = $this->createBranch('01', 'Nong Bua Lamphu');
        $branch02 = $this->createBranch('02', 'Loei');

        $adminSystem = $this->createUserForBranch($branch02, AccessRole::AdminSystem, UserRole::Admin);

        $response = $this->actingAs($adminSystem)->get(route('settings.users.index', [
            'branch_id' => (string) $branch01->id,
        ]));

        $response->assertForbidden();
    }

    public function test_admin_system_cannot_assign_owner_role_when_creating_user(): void
    {
        $branch01 = $this->createBranch('01', 'Nong Bua Lamphu');
        $adminSystem = $this->createUserForBranch($branch01, AccessRole::AdminSystem, UserRole::Admin);

        $response = $this->actingAs($adminSystem)->post(route('settings.users.store'), [
            'full_name' => 'Blocked Owner',
            'employee_code' => 'EMP-BLOCK-001',
            'role' => AccessRole::Owner->value,
            'branch_id' => $branch01->id,
            'is_active' => true,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', [
            'employee_code' => 'EMP-BLOCK-001',
            'access_role' => AccessRole::Owner->value,
        ]);
    }

    public function test_counter_cannot_access_user_management_page(): void
    {
        $branch01 = $this->createBranch('01', 'Nong Bua Lamphu');
        $counter = $this->createUserForBranch($branch01, AccessRole::Counter, UserRole::Sales);

        $response = $this->actingAs($counter)->get(route('settings.users.index'));

        $response->assertForbidden();
    }

    public function test_counter_cannot_call_user_management_actions_directly(): void
    {
        $branch01 = $this->createBranch('01', 'Nong Bua Lamphu');
        $branch02 = $this->createBranch('02', 'Khon Kaen');
        $counter = $this->createUserForBranch($branch02, AccessRole::Counter, UserRole::Sales);
        $target = $this->createUserForBranch($branch02, AccessRole::Counter, UserRole::Sales);

        $storeResponse = $this->actingAs($counter)->post(route('settings.users.store'), [
            'full_name' => 'Unauthorized Create',
            'employee_code' => 'EMP-UNAUTH-001',
            'role' => AccessRole::Counter->value,
            'branch_id' => $branch02->id,
            'is_active' => true,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $storeResponse->assertForbidden();

        $updateResponse = $this->actingAs($counter)->put(route('settings.users.update', $target), [
            'full_name' => 'Unauthorized Update',
            'employee_code' => $target->employee_code,
            'role' => AccessRole::Counter->value,
            'branch_id' => $branch02->id,
            'is_active' => true,
        ]);

        $updateResponse->assertForbidden();

        $toggleResponse = $this->actingAs($counter)->patch(route('settings.users.active.update', $target), [
            'is_active' => false,
        ]);

        $toggleResponse->assertForbidden();

        $deleteResponse = $this->actingAs($counter)->delete(route('settings.users.destroy', $target));

        $deleteResponse->assertForbidden();
    }

    public function test_admin_system_outside_branch_01_cannot_create_user_in_other_branch(): void
    {
        $branch01 = $this->createBranch('01', 'Nong Bua Lamphu');
        $branch02 = $this->createBranch('02', 'Khon Kaen');
        $adminSystem = $this->createUserForBranch($branch02, AccessRole::AdminSystem, UserRole::Admin);

        $response = $this->actingAs($adminSystem)->post(route('settings.users.store'), [
            'full_name' => 'Cross Branch Blocked',
            'employee_code' => 'EMP-CROSS-001',
            'role' => AccessRole::Counter->value,
            'branch_id' => $branch01->id,
            'is_active' => true,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', [
            'employee_code' => 'EMP-CROSS-001',
        ]);
    }

    public function test_owner_in_branch_01_can_create_user_in_other_branch(): void
    {
        $branch01 = $this->createBranch('01', 'Nong Bua Lamphu');
        $branch02 = $this->createBranch('02', 'Khon Kaen');
        $owner = $this->createUserForBranch($branch01, AccessRole::Owner, UserRole::Admin);

        $response = $this->actingAs($owner)->post(route('settings.users.store'), [
            'full_name' => 'Cross Branch Allowed',
            'employee_code' => 'EMP-CROSS-OK-001',
            'role' => AccessRole::Counter->value,
            'branch_id' => $branch02->id,
            'is_active' => true,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', [
            'employee_code' => 'EMP-CROSS-OK-001',
            'branch_id' => $branch02->id,
            'access_role' => AccessRole::Counter->value,
        ]);
    }

    private function createBranch(string $code, string $name): Branch
    {
        return Branch::query()->create([
            'branch_code' => $code,
            'branch_name' => $name,
        ]);
    }

    private function createUserForBranch(
        Branch $branch,
        AccessRole $accessRole,
        UserRole $role,
        StationDepartment $stationDepartment = StationDepartment::None,
    ): User {
        return User::factory()->create([
            'name' => fake()->name(),
            'full_name' => fake()->name(),
            'role' => $role,
            'station_department' => $stationDepartment,
            'access_role' => $accessRole,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }
}
