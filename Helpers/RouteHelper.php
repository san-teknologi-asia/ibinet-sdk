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
}