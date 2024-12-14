<?php

use Illuminate\Support\Facades\Cache;
use Ibinet\Models\Setting;

/**
 * Get the setting value by the given key.
 * 
 * @param string $key
 * @return mixed
 */
function setting($key)
{
    // remember for 1 days
    $ttl = 60 * 24;

    $setting = Cache::remember('setting', $ttl, function () {
        return Setting::all()->pluck('value', 'code');
    });

    if (isset($setting[$key])) {
        return $setting[$key] ?? null;
    }
    
    return null;
}