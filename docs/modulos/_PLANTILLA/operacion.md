# REQ-XXX · Operación

## Variables de entorno

## Servicios externos de los que depende
Y qué ocurre si no responden (circuit breaker, degradación).

## Despliegue: registro de módulos y permisos (`ADR-034 §5`)
`php artisan platform:sync-registry` materializa el descriptor del módulo, su catálogo de permisos y sus *feature flags* en `modules`, `permissions` y `feature_flags`. Se ejecuta en cada despliegue, **antes** de abrir tráfico y antes de provisionar o usar tenants (las claves foráneas de `permission_role` apuntan a `permissions`). Aborta el despliegue si `depends_on` referencia un código inexistente o tiene ciclos, si un esencial depende de uno no esencial, si un ámbito no pertenece al vocabulario cerrado o si dos módulos declaran la misma clave de *flag*. Indica aquí qué cambia este módulo en ese comando y qué hacer si aborta.

## Tareas programadas y jobs

## Métricas y alertas

## Problemas conocidos y diagnóstico

## Impacto en copias de seguridad y restauración
