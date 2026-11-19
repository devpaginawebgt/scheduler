<?php

namespace App\Providers;

use App\Contracts\DisponibilidadServiceContract;
use App\Services\GoogleDisponibilidadService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DisponibilidadServiceContract::class, GoogleDisponibilidadService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
