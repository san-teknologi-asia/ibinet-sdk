<?php

namespace Ibinet\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;
use Ibinet\Models\RolePermission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class PermissionServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Blade::if('has', function ($permission) {
            try {
                $authRoles = Auth::user()->role_id;
                $roleHasPermission = Cache::get('permission');


                if (!$roleHasPermission) {
                    $roleHasPermission = RolePermission::where('role_id', $authRoles)
                        ->get()
                        ->pluck('permission_id')
                        ->toArray();

                    Cache::put('permission', $roleHasPermission, 1440);
                }

                $roleHasPermission = in_array($permission, $roleHasPermission);

                if ($roleHasPermission) {
                    return true;
                }

                return false;
            } catch (\Exception $e) {
                return false;
            }
        });
    }
}
