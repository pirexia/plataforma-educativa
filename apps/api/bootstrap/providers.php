<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuditServiceProvider;
use App\Providers\TenancyServiceProvider;
use App\Support\Modules\ModuleServiceProviderDiscovery;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    AuditServiceProvider::class,
    ...ModuleServiceProviderDiscovery::discover(app_path('Modules')),
];
