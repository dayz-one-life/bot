<?php

namespace App\Services\Admin;

class AdminGuard
{
    /**
     * @param array<int,string> $memberRoleIds role ids the invoking member holds
     */
    public function isAuthorized(array $memberRoleIds, ?string $adminRoleId): bool
    {
        if ($adminRoleId === null || $adminRoleId === '') {
            return false; // no admin role configured -> deny (fail closed)
        }

        return in_array((string) $adminRoleId, array_map('strval', $memberRoleIds), true);
    }
}
