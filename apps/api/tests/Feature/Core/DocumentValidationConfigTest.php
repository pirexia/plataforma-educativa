<?php

use App\Modules\Core\Infrastructure\CoreServiceProvider;

// OPEN-CORE-06, decisión (b): producción fuerza la validación del dígito
// de control en código, no solo en documentación. Verificado aquí en vez
// de confiar en que nadie deje CORE_VALIDATE_DOCUMENT_CHECK_DIGIT=false
// en el entorno de producción.

test('en producción, CoreServiceProvider fuerza validate_check_digit a true aunque la configuración lo desactive', function (): void {
    config(['core.documents.validate_check_digit' => false]);

    app()->detectEnvironment(fn () => 'production');

    (new CoreServiceProvider(app()))->boot();

    expect(config('core.documents.validate_check_digit'))->toBeTrue();

    app()->detectEnvironment(fn () => 'testing');
});

test('fuera de producción, la configuración de entorno se respeta tal cual', function (): void {
    config(['core.documents.validate_check_digit' => false]);

    (new CoreServiceProvider(app()))->boot();

    expect(config('core.documents.validate_check_digit'))->toBeFalse();

    config(['core.documents.validate_check_digit' => true]);
});
