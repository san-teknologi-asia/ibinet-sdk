<?php

namespace Ibinet\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the shared dashboard board view as `ibinet::dashboard.board`.
 */
class DashboardServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'ibinet');
    }
}
