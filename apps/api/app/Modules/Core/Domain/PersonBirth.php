<?php

namespace App\Modules\Core\Domain;

/**
 * REQ-ALUM/funcional.md §7.1 (`OPEN-ALUM-19` = A, ampliación aditiva de `Core\Domain`, 1.15-a): la fecha de nacimiento de una
 * persona, tal como la devuelve `PersonDirectory::birthDatesByIds()`. `birthDate` es `AAAA-MM-DD` o `null` si la persona no
 * la tiene informada. La edad y las reglas que la usan (salida autónoma) son de cada módulo consumidor.
 */
final class PersonBirth
{
    public function __construct(public readonly ?string $birthDate) {}
}
