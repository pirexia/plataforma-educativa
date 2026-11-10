<?php

namespace App\Modules\Acad\Domain;

/**
 * RN-ACAD-21: la entidad de estructura cuyo borrado puede vetar un módulo.
 */
enum StructureEntity: string
{
    case Group = 'group';
    case Course = 'course';
}
