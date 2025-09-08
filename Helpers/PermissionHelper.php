<?php

use Ibinet\Models\RolePermission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

function has($permission)
{
    try {
        $authRoles = Auth::user()->role_id;
        $userId = Auth::user()->id;
        $cacheKey = "permission_user_{$userId}_role_{$authRoles}";
        
        $roleHasPermission = Cache::get($cacheKey);

        if (!$roleHasPermission) {
            $roleHasPermission = RolePermission::where('role_id', $authRoles)
                ->get()
                ->pluck('permission_id')
                ->toArray();

            Cache::put($cacheKey, $roleHasPermission, 1440);
        }

        return in_array($permission, $roleHasPermission);
    } catch (\Exception $e) {
        return false;
    }
}
