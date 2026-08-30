<?php

use Ibinet\Models\RolePermission;
use Illuminate\Support\Facades\Auth;

function has($permission)
{
    // Memoized for the lifetime of the request only. A page render calls has()
    // dozens of times for the same role, and each call was a full table scan of
    // role_permissions. A persistent cache is deliberately not used here: a role
    // edited in IDC must take effect on the next request, not after a TTL.
    static $roleHasPermission = [];

    try {
        $user = Auth::user();
        if (!$user) {
            return false;
        }
        $authRoles = $user->role_id;

        if (!array_key_exists($authRoles, $roleHasPermission)) {
            $roleHasPermission[$authRoles] = RolePermission::where('role_id', $authRoles)
                ->get()
                ->pluck('permission_id')
                ->toArray();
        }

        return in_array($permission, $roleHasPermission[$authRoles]);
    } catch (\Exception $e) {
        return false;
    }
}
