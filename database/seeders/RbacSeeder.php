<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RbacCatalog;
use App\Platform\Access\RoleKey;
use Illuminate\Database\Seeder;

/**
 * Materialises the Phase 1B-2A authorization catalog into the central database.
 *
 * Idempotent: re-running updates existing rows instead of duplicating them, so it
 * is safe to call from tests and from `migrate --seed`.
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $panelMap = PanelAccessPolicy::roleMap();

        $roles = [];
        foreach (RoleKey::ALL as $key) {
            $roles[$key] = Role::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => RbacCatalog::roleName($key),
                    'scope' => RbacCatalog::roleScope($key),
                    'panel' => $panelMap[$key] ?? null,
                    'is_machine' => RbacCatalog::isMachineRole($key),
                    'description' => null,
                ],
            );
        }

        $permissions = [];
        foreach (PermissionKey::ALL as $key) {
            $permissions[$key] = Permission::query()->updateOrCreate(
                ['key' => $key],
                ['scope' => RbacCatalog::permissionScope($key)],
            );
        }

        foreach (RbacCatalog::rolePermissions() as $roleKey => $permissionKeys) {
            $roleId = $roles[$roleKey]->getKey();

            $ids = [];
            foreach ($permissionKeys as $permissionKey) {
                $ids[] = $permissions[$permissionKey]->getKey();
            }

            // Sync only the catalogued permissions so the DB mirrors the catalog.
            $roles[$roleKey]->permissions()->sync($ids);
        }
    }
}
