<?php

// Rutas de negocio versionadas (REST + OpenAPI, INV-006). Cada módulo de
// app/Modules/ registra aquí sus rutas, o desde su propio ServiceProvider,
// conforme se implementen (paso 1.1 en adelante).

require base_path('app/Modules/Core/Http/routes.php');
