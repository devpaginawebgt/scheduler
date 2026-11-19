<?php

namespace App\Providers;

use App\Contracts\DisponibilidadServiceContract;
use App\Services\MockDisponibilidadService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DisponibilidadServiceContract::class, MockDisponibilidadService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
