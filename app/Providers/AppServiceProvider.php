<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'company' => Company::class,
            'contact' => Contact::class,
            'deal' => Deal::class,
        ]);
    }
}
