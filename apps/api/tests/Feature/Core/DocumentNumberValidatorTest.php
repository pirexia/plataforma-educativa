<?php

use App\Modules\Core\Application\DocumentNumberValidator;

// OPEN-CORE-06 (funcional.md §10, resuelta). Casos límite de funcional.md
// §6: "Documento con dígito de control inválido".

test('un DNI con dígito de control correcto es válido', function (): void {
    config(['core.documents.validate_check_digit' => true]);

    // 00000000 % 23 = 0 -> letra T.
    expect((new DocumentNumberValidator)->isValid('DNI', '00000000T'))->toBeTrue();
});

test('un DNI con dígito de control incorrecto es inválido cuando la comprobación está activa', function (): void {
    config(['core.documents.validate_check_digit' => true]);

    expect((new DocumentNumberValidator)->isValid('DNI', '00000000X'))->toBeFalse();
});

test('con la comprobación de dígito desactivada, solo se valida el formato (REQ-SEED-005)', function (): void {
    config(['core.documents.validate_check_digit' => false]);

    expect((new DocumentNumberValidator)->isValid('DNI', '00000000X'))->toBeTrue()
        ->and((new DocumentNumberValidator)->isValid('DNI', 'formato-invalido'))->toBeFalse();
});

test('un NIE válido comprueba el dígito con el prefijo X/Y/Z traducido a dígito', function (): void {
    config(['core.documents.validate_check_digit' => true]);

    // X0000000 -> 00000000 % 23 = 0 -> T.
    expect((new DocumentNumberValidator)->isValid('NIE', 'X0000000T'))->toBeTrue()
        ->and((new DocumentNumberValidator)->isValid('NIE', 'X0000000Z'))->toBeFalse();
});

test('un tipo de documento sin algoritmo conocido solo exige formato no vacío', function (): void {
    config(['core.documents.validate_check_digit' => true]);

    expect((new DocumentNumberValidator)->isValid('PASAPORTE', 'AB123456'))->toBeTrue()
        ->and((new DocumentNumberValidator)->isValid('PASAPORTE', ''))->toBeFalse();
});
