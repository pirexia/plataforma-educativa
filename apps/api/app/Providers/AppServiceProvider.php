<?php

namespace App\Providers;

use App\Models\AcademicYear;
use App\Models\ModuleSubscription;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
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
        // ADR-034 §3: alias estable en audit_logs.auditable_type, nunca el
        // FQCN de PHP — un módulo renombrado (ADR-002) no debe dejar
        // huérfano el histórico de auditoría. enforceMorphMap() (no
        // morphMap()) para que usar un modelo fuera de esta lista en una
        // relación polimórfica falle alto y claro, no en silencio.
        Relation::enforceMorphMap([
            'person' => Person::class,
            'user' => User::class,
            'role' => Role::class,
            'academic_year' => AcademicYear::class,
            'module_subscription' => ModuleSubscription::class,
        ]);
    }
}
