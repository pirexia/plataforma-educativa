<?php

use App\Providers\AppServiceProvider;
use App\Providers\TenancyServiceProvider;
use App\Support\Modules\ModuleServiceProviderDiscovery;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    ...ModuleServiceProviderDiscovery::discover(app_path('Modules')),
];
