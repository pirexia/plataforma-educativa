# memory.md — Estado del proyecto

> Fichero de estado entre sesiones. Claude lo lee al arrancar y lo actualiza tras cada hito.
> Mantenerlo **corto**: si supera unas 150 líneas, archiva lo antiguo en `docs/historial/`.

---

## Estado actual

**Fase**: 0 — Cimientos
**Paso activo**: 0.1 pendiente (inicializar repositorio). 0.2 y 0.10 en curso.
**Rama**: —
**Última sesión anterior**: cambio de entorno a WSL2 (`ADR-030`), módulo `REQ-TRAN` ampliado y reubicado a fase 2 (`ADR-031`), nuevo módulo `REQ-SEED`. Requisitos a 3.0.0.

---

## Decisiones tomadas

| ADR | Decisión |
|-----|----------|
| ADR-001 | Multi-tenant: BD compartida con `tenant_id` + RLS |
| ADR-002 | Monolito modular, no microservicios |
| ADR-004 | Borrado: lógico / anonimización / purga, en tres niveles |
| ADR-007 | Stack: Laravel + Vue 3 TS + PostgreSQL |
| ADR-008 | Móvil: PWA → Capacitor |
| ADR-015 | Segmento: concertados de Madrid, objetivo Colegio Miramadrid |
| ADR-016 | Complementarios a Raíces/Roble, no sustitutos. Excepción: 0-3 privado |
| ADR-020 | Régimen jurídico por etapa, no por tenant |
| ADR-021 | Idiomas: es-ES, en, de, fr |
| ADR-023 | UI: Tailwind + shadcn-vue + TanStack Table |
| ADR-024 | Infra: Compose sobre VPS → Kubernetes en E2 |
| ADR-025 | Auth SPA: cookie de sesión, prohibido JWT en localStorage |
| ADR-026 | Documentación híbrida: raíz + por módulo |
| ADR-027 | Podman sobre RHEL 10 en VM VMware; Quadlet en producción |
| ADR-028 | Topología de red: frontend sin proxy, red externa, `Wants=` no `Requires=` |
| ADR-029 | `public_id` ULID en API y URLs; `TIMESTAMPTZ`, `text`, céntimos enteros |
| ADR-030 | Desarrollo en WSL2, equipo personal, **solo datos sintéticos** |
| ADR-031 | `REQ-TRAN` ampliado a 12 requisitos, SHOULD, fase 2 |
| ADR-032 | Lista maestra única de autorizados a recoger, en `REQ-FAM-UNIT-005` |

---

**Última sesión**: guía de puesta en marcha del entorno (`docs/SETUP-ENTORNO.md`) y presentación comercial en `marketing/`. Nombre de marca pendiente de decidir.

## Trabajo en curso

- `.claude/agents/` — 9 subagentes definidos con modelo asignado
- `.claude/skills/` — 5 skills propias: aislamiento-tenant, modulo-nuevo, migracion-segura, i18n-cuatro-idiomas, cierre-de-sesion
- `.claude/settings.json` — permisos con denegación de operaciones destructivas y lectura de secretos
- `docs/SETUP-CLAUDE-CODE.md` — plugins, MCP y orden de instalación
- `README.md`, `docs/modulos/_PLANTILLA/` (5 ficheros) y `docs/adr/README.md`
- `docs/adr/ADR-028` y `ADR-029` en fichero propio
- **10 skills propias** en `.claude/skills/`
- `CHANGELOG.md` con el versionado de todos los documentos
- MCP decididos: GitHub y Context7 ahora; Boost, Playwright y PostgreSQL conforme avance el plan
- **Entorno cambiado a WSL2** en equipo personal (Ryzen 7, 16 GB, SSD 512 GB). La VM RHEL/VMware queda descartada para desarrollo y disponible como preproducción si la titularidad resulta adecuada.
- Pendiente: montar WSL2 con Podman y perfil reducido, instalación real de plugins y MCP, y lista de comprobación de `docs/SETUP-CLAUDE-CODE.md` sección 7

---

## Bloqueantes

| ID | Descripción | Impacto |
|----|-------------|---------|
| H0 | Sin centro piloto comprometido ni ficheros de exportación de GQdalya | Criterio de salida de fase 1 y `REQ-ONB-003` |
| OPEN-11 | **Dónde se aloja el piloto**. WSL2 no puede alojar datos reales | Hito H0 |
| OPEN-07 | Entidad jurídica y contrato de encargado de tratamiento | Datos reales y facturación |
| OPEN-08 | Dominio y DNS con API para certificado comodín | Multi-tenant, fase 0 |
| OPEN-09 | Proveedor de correo transaccional | `REQ-AUTH`, `REQ-COM` |
| OPEN-10 | Almacenamiento de copias en proveedor distinto del host | `REQ-BKP` |

---

## Problemas abiertos

_Ninguno._

---

## Siguiente paso concreto

Seguir `docs/SETUP-ENTORNO.md` de principio a fin: monta WSL2, Podman, Claude Code y el repositorio con ramas `main` y `develop`, volcar los ficheros ya preparados (`CLAUDE.md`, `memory.md`, `.gitignore`, `.claude/`, `docs/`) y hacer el primer commit. Después, verificar el paso 0.2 con la lista de comprobación de `docs/SETUP-CLAUDE-CODE.md`.
