<?php

namespace Ibinet\Helpers;

class RouteHelper{
    /**
     * Returning to SSO Profile
     * 
     * @return void
     */
    public static function routeProfile()
    {
        return redirect()->away(env('SSO_URL') . '/profile');
    }

    /**
     * Returning to SSO Logout
     * 
     * @return void
     */
    public static function routeLogout()
    {
        return redirect()->away(env('SSO_URL') . '/logout');
    }
}