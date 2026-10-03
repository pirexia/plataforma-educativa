<?php

namespace App\Modules\Core\Domain;

/**
 * api.md §14.4 (1.9d, S10, `OPEN-CORE-34` = B): el catálogo declarado en
 * código de los valores filtrables del registro de auditoría. Una sola
 * fuente para el filtro (`AuditLogFilter`), la validación
 * (`IndexAuditLogsRequest`) y el endpoint de facetas
 * (`AuditLogFacetsController`), de modo que lo que la pantalla ofrece y lo
 * que el servidor acepta no puedan divergir. No consulta `audit_logs`.
 */
final class AuditCatalog
{
    /**
     * Módulo → alias del morph map (`auditable_type`) que declara. En 1.1
     * solo existe `core`.
     *
     * @var array<string, list<string>>
     */
    public const MODULE_ALIASES = [
        'core' => [
            'person', 'user', 'role', 'academic_year', 'module_subscription',
            'tenant_setting', 'user_invitation', 'user_import', 'data_export',
        ],
    ];

    /** ADR-039 §4.1 (nueve valores, `datos.md` Parte 0.9). */
    public const EVENTS = [
        'created', 'updated', 'deleted', 'restored', 'read', 'exported',
        'login', 'logout', 'password_reset_requested',
    ];

    /** ADR-039 §4.1: `anonymous` para peticiones sin sesión (OPEN-AUTH-12). */
    public const ACTOR_TYPES = ['user', 'system', 'console', 'import', 'platform', 'anonymous'];

    /**
     * @return list<string>
     */
    public static function modules(): array
    {
        return array_keys(self::MODULE_ALIASES);
    }

    /**
     * @return list<array{alias: string, module: string}>
     */
    public static function auditableTypes(): array
    {
        $types = [];

        foreach (self::MODULE_ALIASES as $module => $aliases) {
            foreach ($aliases as $alias) {
                $types[] = ['alias' => $alias, 'module' => $module];
            }
        }

        return $types;
    }

    /**
     * Alias de `auditable_type` de una lista de módulos; un código sin
     * correspondencia no aporta ninguno.
     *
     * @param  list<string>  $modules
     * @return list<string>
     */
    public static function aliasesOf(array $modules): array
    {
        $aliases = [];

        foreach ($modules as $module) {
            array_push($aliases, ...(self::MODULE_ALIASES[$module] ?? []));
        }

        return array_values(array_unique($aliases));
    }
}
