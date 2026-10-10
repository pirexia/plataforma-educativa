<?php

use App\Modules\Core\Application\DocumentNumberValidator;
use App\Modules\Core\Domain\DocumentType;

// OPEN-CORE-06 (funcional.md §10, resuelta). Casos límite de funcional.md
// §6: "Documento con dígito de control inválido". Desde 1.9c el validador
// trabaja sobre el catálogo cerrado `DocumentType` (RN-CORE-90, #292).

test('un DNI con dígito de control correcto es válido', function (): void {
    config(['core.documents.validate_check_digit' => true]);

    // 00000000 % 23 = 0 -> letra T.
    expect((new DocumentNumberValidator)->isValid(DocumentType::Dni, '00000000T'))->toBeTrue();
});

test('un DNI con dígito de control incorrecto es inválido cuando la comprobación está activa', function (): void {
    config(['core.documents.validate_check_digit' => true]);

    expect((new DocumentNumberValidator)->isValid(DocumentType::Dni, '00000000X'))->toBeFalse();
});

test('con la comprobación de dígito desactivada, solo se valida el formato (REQ-SEED-005)', function (): void {
    config(['core.documents.validate_check_digit' => false]);

    expect((new DocumentNumberValidator)->isValid(DocumentType::Dni, '00000000X'))->toBeTrue()
        ->and((new DocumentNumberValidator)->isValid(DocumentType::Dni, 'formato-invalido'))->toBeFalse();
});

test('un NIE válido comprueba el dígito con el prefijo X/Y/Z traducido a dígito', function (): void {
    config(['core.documents.validate_check_digit' => true]);

    // X0000000 -> 00000000 % 23 = 0 -> T.
    expect((new DocumentNumberValidator)->isValid(DocumentType::Nie, 'X0000000T'))->toBeTrue()
        ->and((new DocumentNumberValidator)->isValid(DocumentType::Nie, 'X0000000Z'))->toBeFalse();
});

// RN-CORE-90, OPEN-CORE-51 = A (CA-CORE-274).
test('CA-CORE-274: el pasaporte exige alfanumérico de 1 a 32 caracteres y no lleva dígito de control', function (): void {
    config(['core.documents.validate_check_digit' => true]);

    $validator = new DocumentNumberValidator;

    expect($validator->isValid(DocumentType::Pasaporte, 'AB1234567'))->toBeTrue()
        ->and($validator->isValid(DocumentType::Pasaporte, str_repeat('A', 32)))->toBeTrue()
        ->and($validator->isValid(DocumentType::Pasaporte, str_repeat('A', 33)))->toBeFalse()
        ->and($validator->isValid(DocumentType::Pasaporte, ''))->toBeFalse()
        ->and($validator->isValid(DocumentType::Pasaporte, 'AB 123'))->toBeFalse()
        ->and($validator->isValid(DocumentType::Pasaporte, 'AB-123'))->toBeFalse();
});

// RN-CORE-92, OPEN-CORE-49 = A (CA-CORE-275, F5).
test('RN-CORE-92: el número se normaliza (recorte y mayúsculas; sin espacios ni guiones en dni/nie)', function (): void {
    $validator = new DocumentNumberValidator;

    expect($validator->normalize(DocumentType::Dni, ' 12345678-z '))->toBe('12345678Z')
        ->and($validator->normalize(DocumentType::Dni, '12 345 678z'))->toBe('12345678Z')
        ->and($validator->normalize(DocumentType::Nie, 'x-1234567-l'))->toBe('X1234567L')
        ->and($validator->normalize(DocumentType::Pasaporte, ' ab123 '))->toBe('AB123')
        // Los puntos no se quitan: 12.345.678-Z es un error de formato.
        ->and($validator->isValidFormat(DocumentType::Dni, '12.345.678-Z'))->toBeFalse();
});

// F5 (#310): con el dígito desactivado, un número en minúsculas ya no se
// acepta en minúsculas: se valida (y se guarda) normalizado.
test('F5 (#310): con la comprobación desactivada un DNI en minúsculas es válido solo porque se normaliza', function (): void {
    config(['core.documents.validate_check_digit' => false]);

    $validator = new DocumentNumberValidator;

    expect($validator->isValid(DocumentType::Dni, '12345678z'))->toBeTrue()
        ->and($validator->normalize(DocumentType::Dni, '12345678z'))->toBe('12345678Z');
});

test('RN-CORE-90, OPEN-CORE-47 = A y OPEN-CORE-46 = B: el catálogo son tres códigos en minúsculas y sin «otro»', function (): void {
    expect(DocumentType::values())->toBe(['dni', 'nie', 'pasaporte'])
        ->and(DocumentType::fromCode('DNI'))->toBeNull()
        ->and(DocumentType::fromCode('otro'))->toBeNull()
        ->and(DocumentType::fromLooseCode(' Dni '))->toBe(DocumentType::Dni)
        ->and(DocumentType::fromLooseCode('passport'))->toBeNull();
});
