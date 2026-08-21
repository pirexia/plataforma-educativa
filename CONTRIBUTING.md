# CONTRIBUTING.md

> **Versión 0.1.0** · 2026-08-18
> Estilo de código, flujo de trabajo Git y revisión de código. Extraído de `CLAUDE.md`, que es la fuente de verdad si algo difiere.

---

## 1. Flujo de trabajo Git

- Ramas permanentes: **`main`** (producción) y **`develop`** (integración). Nunca se commitea directamente en ninguna de las dos.
- Todo desarrollo cuelga de `develop`:
  - `feature/REQ-XXX-descripcion-corta`
  - `fix/REQ-XXX-descripcion-corta`
  - `chore/descripcion-corta`
- Merge a `develop` al terminar, con **borrado inmediato de la subrama** local y remota.
- `main` solo recibe merges desde `develop` en release, etiquetados con versión semántica.
- Formato de commit: `tipo(ámbito): descripción [REQ-XXX-NNN]`. Todo commit referencia al menos un ID de requisito o de issue.
- Antes de cualquier merge: tests en verde, lint limpio, documentación del módulo actualizada.

## 2. Revisión de código

Obligatoria antes de mezclar a `develop`:

| Revisor | Cuándo |
|---------|--------|
| `db-reviewer` | Cualquier cambio bajo `database/migrations` |
| `security-reviewer` | Todo merge a `develop` |
| `doc-reviewer` | Cierre de cada fase, y cualquier módulo que toque documentación de usuario/API |

Un hallazgo se clasifica por severidad (`CLAUDE.md` §5) y se documenta en un issue de GitHub, aunque se corrija en la misma sesión. Nunca se corrige en silencio.

## 3. Estilo de código

| Capa | Herramienta | Comando |
|------|-------------|---------|
| PHP | Pint | `./vendor/bin/pint` |
| PHP (análisis estático) | Larastan (nivel 6) | `./vendor/bin/phpstan analyse` |
| PHP (tests) | Pest | `php artisan test` |
| TypeScript/Vue | ESLint + Prettier | `npm run lint` |
| TypeScript/Vue (tests) | Vitest, Playwright | `npm run test` |

Reglas que el linter no puede verificar por sí solo:

- **Ningún literal visible en el código** (`INV-009`): todo texto de interfaz, documento generado o notificación pasa por el sistema de traducción, en los cuatro idiomas obligatorios (`es-ES`, `en`, `de`, `fr`).
- **Aislamiento de tenant a nivel de framework** (`INV-001`), nunca solo en el controlador ni confiando en que el cliente envíe el `tenant_id` correcto.
- **Autorización verificada en cada endpoint, denegando por defecto** (`INV-002`).
- **Auditoría y borrado lógico** (`INV-003`/`INV-004`) vía los helpers ya existentes (`App\Support\Tenancy\TenantMigration`/`TenantModel`), nunca reinventados por migración o por modelo.
- **Un módulo no importa código interno de otro** (`INV-007`): comunicación por interfaces o eventos de dominio.

Lista completa de invariantes: sección 0.5 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`.

### 3.1. Regenerar `_ide_helper_models.php` tras una migración nueva

Larastan infiere las propiedades mágicas de un modelo Eloquent escaneando `Schema::create(...)` de forma estática, pero este proyecto declara las columnas de tenant vía `App\Support\Tenancy\TenantMigration::tenantTable()`/`tenantTableAppendOnly()` (issue [#51](https://github.com/pirexia/plataforma-educativa/issues/51)), que el escáner nunca ve. La solución es `barryvdh/laravel-ide-helper` (dependencia solo de desarrollo):

1. Tras crear o modificar una migración y aplicarla en desarrollo (`php artisan migrate --database=pgsql_owner`), regenera el fichero:
   ```
   php artisan ide-helper:models --nowrite
   ```
2. **Nunca uses `--write` ni `--write-mixin`**: `--write` duplica el docblock completo en cada modelo (diverge del esquema real en cuanto alguien lo edita a mano); `--write-mixin` reescribe el docblock *entero* del modelo con su propio serializador y puede corromper texto existente (ocurrió en la práctica: partió "0..1" en "0." / ".1" en un comentario de `Person.php`). El fichero generado (`_ide_helper_models.php`, en la raíz de `apps/api`) se **commitea tal cual** — es la única forma de que Larastan lo use sin depender de una base de datos en CI (el job `static-analysis` de `ci-api.yml` no tiene servicio de PostgreSQL). Riesgo aceptado: si alguien migra sin regenerar, el fichero queda desincronizado hasta el siguiente `phpstan analyse` local que lo note — no hay comprobación automática de esa divergencia todavía.
3. Cada modelo real lleva una línea `@mixin IdeHelperNombreDelModelo` en su propio docblock (footprint mínimo, escrito a mano una vez por modelo — no lo genera `--write-mixin`, ver punto 2). Es lo único que hace que PHPStan conecte las propiedades del fichero generado con la clase real: sin ese `@mixin`, `scanFiles: [_ide_helper_models.php]` en `phpstan.neon` no tiene ningún efecto (los `class IdeHelperX {}` quedan sueltos, sin relación con `App\Models\X`). Un modelo nuevo necesita esa línea añadida a mano una vez; el contenido de sus propiedades se regenera solo.

## 4. Abrir un módulo nuevo

1. El módulo tiene que existir como `REQ-XXX` en `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`. No se inventan requisitos.
2. Especificación funcional y técnica primero (subagente `spec-writer`), en `docs/modulos/REQ-XXX/` a partir de la plantilla de `docs/modulos/_PLANTILLA/`. No se implementa código en este paso.
3. Implementación siguiendo la especificación aprobada, con tests que referencien el ID del requisito (`INV-015`).
4. Documentación del módulo, manual de usuario del rol afectado y `SYSADMIN.md`/`RUNBOOK.md` actualizados si el módulo introduce infraestructura, variables de entorno o procedimientos operativos nuevos.
5. Revisión de código (sección 2) antes de mezclar.

Un módulo no se cierra sin su documentación actualizada — forma parte de la definición de terminado (`CLAUDE.md` §10), no es una tarea posterior.

## 5. Gestión de incidencias

Toda incidencia detectada durante el trabajo se clasifica por severidad y se documenta en un issue de GitHub, con descripción, reproducción, ficheros implicados, severidad, requisitos afectados y propuesta de solución. Tabla completa de severidades y plazos de resolución: `CLAUDE.md` §5.
