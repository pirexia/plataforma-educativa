<?php

namespace App\Support\Authorization;

/**
 * ADR-044 §4.1, RPERM-004, RN-PERM-01: vocabulario cerrado de ámbitos, de
 * todo el dominio educativo, no de un módulo. Fijado en un `enum` de PHP y
 * en el `CHECK` de `permission_role.scope` (datos.md §2). Añadir un
 * séptimo exige un ADR nuevo — no una lista abierta que cada módulo pueda
 * ampliar.
 *
 * Solo `Todos` y `Propios` tienen resolutor registrado en 1.5
 * (`ScopeResolverRegistry`, funcional.md §3.1). Los otros cuatro existen en
 * el vocabulario para que el `CHECK` los admita desde hoy, pero conceder un
 * permiso con ellos responde 422 hasta que `REQ-ACAD` (1.11) o
 * `REQ-FAM-UNIT` (1.14) registren su resolutor (RN-PERM-04/05).
 */
enum Scope: string
{
    case Todos = 'todos';
    case Propios = 'propios';
    case Departamento = 'departamento';
    case Grupo = 'grupo';
    case Clase = 'clase';
    case UnidadFamiliar = 'unidad_familiar';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
