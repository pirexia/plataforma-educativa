<?php

namespace App\Providers;

use App\Support\Audit\AuditChangeBuilder;
use App\Support\Audit\AuditRecorder;
use App\Support\Http\RequestId;
use Illuminate\Support\ServiceProvider;

/**
 * ADR-035 (0.9.a-c): infraestructura de auditoría. No es un bounded
 * context de negocio (INV-007), igual que TenancyServiceProvider.
 */
class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RequestId::class);

        $this->app->singleton(AuditChangeBuilder::class, function (): AuditChangeBuilder {
            return new AuditChangeBuilder(
                secretPatterns: config('audit.secret_attribute_patterns'),
                maxValueLength: config('audit.max_value_length'),
            );
        });

        $this->app->singleton(AuditRecorder::class);
    }
}
