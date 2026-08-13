# memory.md — Estado del proyecto

> Fichero de estado entre sesiones. Claude lo lee al arrancar y lo actualiza tras cada hito.
> Mantenerlo **corto**: si supera unas 150 líneas, archiva lo antiguo en `docs/historial/`.

---

## Estado actual

**Fase**: 0 — Cimientos
**Paso activo**: 0.1 y 0.2 cerrados. 0.3 en curso (falta `api`, `web` y servicio de PDF). 0.10 sigue abierto.
**Rama**: `chore/cierre-fase0-repo-claude-code` (colgada de `develop`, sin mergear)
**Última sesión**: cierre de 0.1, 0.2 y avance de 0.3. Ver `Trabajo en curso`.

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

## Trabajo en curso

- **0.1 cerrado**: `LICENSE` propietaria (titular provisional: Andrés Matías López, pendiente de `OPEN-07`), `.gitignore` con patrones de Python, eliminado `SKILL.md` suelto de la raíz.
- **0.2 cerrado**: MCP de GitHub y Context7 conectados y verificados con las 4 pruebas de `docs/SETUP-ENTORNO.md` §7.4 (issue de prueba #1 creado y cerrado). Plugin `laravel/agent-skills` instalado. 9 agentes con modelo correcto, 10 skills.
- **0.3 en curso**: Podman, red externa `plataforma-net` y `.wslconfig` ya estaban operativos de una sesión previa. `compose.yaml` nuevo con perfil reducido: `postgres` + `redis` arrancan por defecto y probados en `healthy`; `minio` detrás de `--profile full`. `SYSADMIN.md` creado (v0.1.0). Falta: `api` y `web` (pasos 0.4/0.5), servicio de PDF (motor sin decidir, ver 1.17).
- MCP pendientes: Boost tras 0.4, Playwright tras 0.5, PostgreSQL tras 0.8.

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

| ID | Descripción | Severidad |
|----|-------------|-----------|
| P-01 | El subagente `spec-writer` está bien definido en `.claude/agents/spec-writer.md` (modelo Opus) pero no aparece en la lista de subagentes disponibles de la sesión. Anomalía del entorno de Claude Code, no del repositorio. Revisar al empezar la próxima sesión; si persiste, investigar registro de agentes. | Media |

---

## Siguiente paso concreto

1. Mergear `chore/cierre-fase0-repo-claude-code` a `develop` (tests no aplica todavía, no hay código de aplicación) y borrar la rama.
2. Retomar 0.3: decidir el motor de renderizado PDF (o posponerlo explícitamente a 1.17) y dejarlo dicho en un ADR si se decide ahora.
3. Empezar 0.4 (esqueleto de la API Laravel), que es lo que permite completar `api` en `compose.yaml`.
4. Comprobar si `spec-writer` (P-01) sigue sin aparecer en `/agents`.
