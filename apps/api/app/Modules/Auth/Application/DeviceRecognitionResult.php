<?php

namespace App\Modules\Auth\Application;

use App\Modules\Auth\Domain\Models\UserKnownDevice;

/**
 * funcional.md §B.4.5. `rawCookieValue` solo viene informado cuando
 * `isNew` es `true` — es el valor en claro que hay que emitir en la
 * cookie `pge_device` de la respuesta, y no se persiste en ningún sitio
 * (`RN-AUTH-45`). `shouldAlert` distingue "dispositivo nuevo" de
 * "dispositivo nuevo y además avisado": el tope diario de
 * `RN-AUTH-46` puede dejar el primero sin el segundo.
 */
final class DeviceRecognitionResult
{
    public function __construct(
        public readonly UserKnownDevice $device,
        public readonly bool $isNew,
        public readonly ?string $rawCookieValue,
        public readonly bool $shouldAlert,
    ) {}
}
