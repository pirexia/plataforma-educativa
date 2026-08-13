<?php

use App\Providers\AppServiceProvider;
use App\Support\Modules\ModuleServiceProviderDiscovery;

return [
    AppServiceProvider::class,
    ...ModuleServiceProviderDiscovery::discover(app_path('Modules')),
];
