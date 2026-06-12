<?php

namespace App\SlashCommands\Concerns;

use App\Services\Admin\AdminGuard;

trait GuardsAdmin
{
    /**
     * Return the invoking member's role ids as an array of strings.
     *
     * Member::getRolesAttribute() builds an ExCollectionInterface keyed by the
     * role-id string (from array_fill_keys($attributes['roles'], null)).
     * Iterating via array_keys therefore gives us the raw snowflake id strings
     * regardless of whether Role objects are cached or not.
     *
     * @return array<int,string>
     */
    protected function memberRoleIds($interaction): array
    {
        $roles = $interaction->member->roles ?? [];
        $ids = [];
        foreach ($roles as $idOrRole => $roleOrNull) {
            // The collection is keyed by role-id string; values may be Role objects or null.
            // Cast the key to string for safety.
            $ids[] = (string) $idOrRole;
        }
        return $ids;
    }

    /**
     * Reply with a denial message and return true if the caller is NOT an admin.
     * Returns false when the caller IS authorized (so handle() can continue).
     */
    protected function denyIfNotAdmin($interaction): bool
    {
        if ((new AdminGuard())->isAuthorized($this->memberRoleIds($interaction), env('ADMIN_ROLE_ID'))) {
            return false;
        }
        $this->message('⛔ You are not authorized to use this command.')->reply($interaction, ephemeral: true);
        return true;
    }
}
