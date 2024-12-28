<?php

namespace Ibinet\Helpers;

use Ixudra\Curl\Facades\Curl;

class MapsHelper{
    /**
     * Get Detail Maps by Lat Long
     *
     * @param String $lat_long
     */
    public static function getDetailLocation($lat_long)
    {
        $googleMapsApiKey = env('GOOGLE_MAPS_API_KEY');
        $response = Curl::to("https://maps.googleapis.com/maps/api/geocode/json?latlng={$lat_long}&key={$googleMapsApiKey}")
            ->get();

        return $response;
    }
}
