<?php

namespace Ibinet\Helpers;

class RouteHelper
{
    /**
     * Returning to SSO Profile
     * 
     * @return void
     */
    public static function routeProfile()
    {
        return env('SSO_URL') . '/profile';
    }

    /**
     * Returning to SSO Logout
     * 
     * @return void
     */
    public static function routeLogout()
    {
        return env('SSO_URL') . '/logout';
    }

    /**
     * Check if the current route is active based on the given route name.
     *
     * @param string $routeName The base name of the route to match.
     * @return string 'active' if the route matches, '' otherwise.
     */
    public static function routeActive($routeName)
    {
        return request()->routeIs($routeName . '.*') ? 'active' : '';
    }
}
