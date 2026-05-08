<?php

declare(strict_types=1);

namespace App\Domain\Users;

final class PermissionMatrix
{
    public function __construct(private array $roles, private array $availablePermissions)
    {
    }

    public function roles(): array
    {
        $items = [];

        foreach ($this->roles as $slug => $config) {
            $items[] = [
                'slug' => $slug,
                'label' => $config['label'] ?? $slug,
                'permissions' => $config['permissions'] ?? [],
            ];
        }

        return $items;
    }

    public function allPermissions(): array
    {
        return $this->availablePermissions;
    }

    public function permissionsForRoles(array $roleSlugs): array
    {
        $permissions = [];

        foreach ($roleSlugs as $slug) {
            $rolePermissions = $this->roles[$slug]['permissions'] ?? [];

            if (in_array('*', $rolePermissions, true)) {
                return $this->availablePermissions;
            }

            $permissions = [...$permissions, ...$rolePermissions];
        }

        $permissions = array_values(array_unique($permissions));
        sort($permissions);

        return $permissions;
    }
}

