# Plataforma de Gestión Educativa Multi-tenant

SaaS para la gestión integral de centros educativos. Segmento inicial: **centros concertados de la Comunidad de Madrid**, con primer ciclo de Educación Infantil en régimen privado.

| Campo | Valor |
|-------|-------|
| **Versión del documento** | 2.6.29 |
| **Fecha** | 2026-10-21 |
| **Estado del proyecto** | Fase 1 · MVP operativo. Bloque A (identidad y acceso) **completo y mezclado**: tenants/usuarios, autenticación local, sesiones activas, MFA (TOTP + correo), login con Google (fusión de cuentas), SSO institucional OIDC + SAML 2.0 con aprovisionamiento por emparejamiento, núcleo de autorización granular (`REQ-PERM`) y los cinco sub-pasos del backoffice de plataforma (`REQ-BO`, `1.6`-`1.6e`, incluido el motor de *feature flags*) — `1.1` a `1.6e` íntegros. Bloque B (*design system* y navegación): `1.7` · *Design system* (tokens, tema por tenant, modo oscuro) **completo y mezclado**. `1.8` · Layout, navegación y panel de inicio (`REQ-CORE-008`) implementado y mezclado (PR #264). `1.9` (Tablas de datos): especificación y `ADR-054` (aceptada) aprobados el 2026-09-30; **implementado** (componente `apps/web/src/data-table/` y migración de `MfaComplianceArea.vue`), revisión independiente hecha (sin Crítico/Alto) y mezclado. `1.9b` (usuarios e invitaciones; la especificación de las pantallas de gestión de `REQ-CORE`, aprobada el 2026-10-01, divide el trabajo en 1.9b a 1.9f) **implementado** el 2026-10-02 (pantallas de usuarios e invitaciones, exportación de usuarios, `alert-dialog`), revisión independiente hecha y mezclado. `1.9c` (importación de usuarios y catálogo cerrado de tipos de documento de identidad, `RN-CORE-90` a `-93`) **implementado** el 2026-10-02/03 (pantallas de importación, migración de datos que normaliza `people`, idioma de los mensajes de importación), revisión independiente hecha (`db-`, `security-` y `doc-reviewer`, sin Crítico/Alto) y mezclado. `1.9d` (auditoría y roles de solo lectura) **implementado** el 2026-10-03 (pantallas `core-audit` y `core-roles`, `GET /audit-logs/facets`, filtro `entity` del componente de tablas, «Ver su actividad»), revisión independiente hecha (`security-` y `doc-reviewer`, sin Crítico/Alto) y mezclado (PR #320). `1.9e` (configuración del centro, activos de marca, módulos contratados en solo lectura y perfil propio; `OPEN-CORE-37` = B, `-45` = A) **implementado** el 2026-10-03, revisión independiente hecha (sin Crítico/Alto) y mezclado (PR #325). `1.9f` (migración de las tres tablas de `REQ-AUTH` —`MfaExemptionsArea`, `AdminSsoView`, `SessionsView`— al componente de tablas, con el diálogo de confirmación común y el filtro `enum` de selección única; `OPEN-CORE-54`/`-55`/`-56` = A) **implementado** el 2026-10-04, revisión independiente hecha (`security-` sin Crítico/Alto/Medio; `doc-reviewer`, 3 Media corregidas), mezclado. **Cierra la serie 1.9b-1.9f: la interfaz de `REQ-CORE` está completa** y la lista de excepciones de `RN-CORE-53` queda vacía. Tras `1.9f`, una tanda de correcciones (2026-10-04/05) cerró las Baja #280 (una exportación sin solicitante falla en vez de exportar todo el centro), #323 y #324 (configuración de seguridad), #331, #253, #322, #239, #276 y #326. `1.5b` (`REQ-PERM`, editor de roles, matriz de concesión y permisos efectivos, más S-PERM-1, S-PERM-2 y la regla `RN-PERM-47`) **cerrado y mezclado** el 2026-10-05 (PR #354). `1.7b` (estandarización de módulos, `ADR-056`, aceptada el 2026-10-07: reglas de arquitectura `AR-*` comprobadas por test, `ARCHITECTURE.md §3.4` y plantilla de módulo actualizadas; el generador `make:module` queda diferido a `1.11b`) **cerrado y mezclado el 2026-10-07** (PR #379). `1.10` (`REQ-CURSO`, ciclo de vida del curso y bloqueo de escritura por disparador) **cerrado y mezclado** el 2026-10-08 (PR #386). `1.11` (`REQ-ACAD`, estructura académica: etapas, cursos de etapa, grupos, subgrupos, asignaturas y su asignación): `1.11-a` (infraestructura compartida, `ADR-062`/`ADR-063`) mezclada el 2026-10-12 (PR #417); `1.11-b` (módulo `acad`: seis tablas con el disparador de curso, 27 *endpoints*, cinco pantallas, selector de curso y campo traducible) **mezclada el 2026-10-12** (PR #423). `1.11d` (períodos lectivos y escalas): especificación mezclada el 2026-10-17 (PR #433) y entrega `1.11d-a` (períodos lectivos y, en `curso`, validación del cambio de fechas, bloqueo compartido y filtro por módulo contratado) **mezclada** (PR #435). `1.11d-b` (escalas de calificación: tablas, 5 *endpoints* y cuatro pantallas) **mezclada** (PR #439). `1.11c` (`REQ-CURSO`: reapertura de un curso cerrado, `ADR-059`) **mezclada** (PR #443). `1.12-a` (`REQ-ACAD-002`: asignación docente, tutorías, espacios, marcos horarios ordinarios, sesiones con detección de conflictos y vistas semanales; 21 *endpoints*) **mezclada el 2026-10-18/19** (PR #447). `1.12-b` (`REQ-ACAD-002`: marcos horarios especiales —jornada intensiva— con su efecto en los conflictos, y reservas de aulas y recursos con el ámbito `propios` en `reservas.eliminar`; 4 *endpoints* nuevos) **mezclada** (PR #449). Las Bajas de `1.12-a`/`1.12-b` (#446, #450 y #451: endurecimiento de esquema, usuario borrado con marcador neutro, notas de seguridad, E2E y prueba lenta de auditoría) están resueltas en su rama, pendientes de mezcla. `1.12b` (`REQ-CURSO-002`: apertura del curso siguiente o *rollover* —asistente de cuatro pasos, simulación, copia y reversión en cola, participante de `acad` con la casilla opcional de asignación docente; 7 *endpoints* nuevos y un segundo proceso de cola, `worker-long`) **implementada el 2026-10-21** en la rama `feature/REQ-CURSO-1-12b-rollover`, pendiente de revisión independiente y de mezcla. Paso nuevo `1.15a` (capa visual de la interfaz, `ADR-064`) antes de `1.15b` |

---

## Qué es y qué no es

Complementa a **Raíces/Roble**, no los sustituye. Raíces es el sistema oficial de registro para matrícula, evaluación final, promoción, NEAE y documentos oficiales. Esta plataforma cubre la gestión **interna** del centro y su propuesta de valor es **eliminar la doble grabación**.

Excepción: en el **primer ciclo de Infantil (0-3) en régimen privado** sí somos el sistema oficial de registro.

---

## Mapa de documentos

| Documento | Para qué | Cuándo leerlo |
|-----------|----------|---------------|
| **`CLAUDE.md`** | Normas de trabajo. Se carga en todas las sesiones. | Siempre, primero |
| **`memory.md`** | Estado entre sesiones: dónde estamos y qué toca | Al arrancar cada sesión |
| **`PLAN-IMPLEMENTACION.md`** | Pasos de ejecución, dimensionados a sesiones de 5 h | Al arrancar cada sesión |
| **`ARCHITECTURE.md`** | Stack, estructura, despliegue, dimensionado de hardware | Antes de tocar arquitectura |
| **`SYSADMIN.md`** | Entorno de desarrollo, `compose.yaml`, operación | Antes de tocar infraestructura |
| **`docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`** | Fuente de verdad funcional. 53 módulos especificados (secciones 5.1-5.53; hoy hay 5 bounded contexts implementados en `apps/api/app/Modules`), 65 ADR en el índice | Al empezar un módulo |
| **`docs/i18n.md`** | Convención de internacionalización, backend y frontend (`ADR-021`/`INV-009`) | Antes de escribir cualquier texto visible |
| **`docs/SETUP-ENTORNO.md`** | **Puesta en marcha completa**: WSL2, Podman, Claude Code, repositorio | Antes que nada |
| **`docs/SETUP-CLAUDE-CODE.md`** | Plugins, MCP, subagentes y skills | Al configurar el entorno |
| **`seed/README.md`** | Generador de datos sintéticos | Para probar con volumen |
| **`marketing/`** | Presentación comercial | Para hablar con centros |
| **`SECURITY.md`** | Arquitectura de seguridad, reporte de vulnerabilidades | Antes de tocar autenticación o permisos |
| **`PRIVACY.md`** | Tratamientos, bases legales, retención (base del RAT) | Antes de modelar cualquier dato de personas |
| **`RUNBOOK.md`** | Procedimientos operativos ante incidencias | Cuando algo falla |
| **`CONTRIBUTING.md`** | Estilo de código, flujo Git, revisión de código | Antes del primer commit |

---

## Stack

| Capa | Tecnología |
|------|-----------|
| Backend | Laravel (PHP 8.4+), API REST modular |
| Frontend | Vue 3 + TypeScript + Vite (SPA) |
| UI | Tailwind CSS + shadcn-vue + TanStack Table |
| Base de datos | PostgreSQL 18 |
| Caché y colas | Redis (caché); colas: `database` con worker `queue:work` (#128), Redis + Horizon elegido, no instalado |
| Contenedores | Podman |
| Desarrollo | WSL2 en equipo personal · solo datos sintéticos |
| Identificadores | `bigint` interno + `public_id` ULID en API y URLs |
| Alojamiento del piloto | Pendiente de decidir (`OPEN-11`). VM VMware (4 vCPU / 16 GB / 160 GB) disponible como candidata si su titularidad resulta adecuada (`ADR-027`/`ADR-030`) |

---

## Reglas que no se negocian

0. **Ningún dato real en desarrollo.** El entorno es un equipo personal: solo datos sintéticos generados por `REQ-SEED`.
1. **Aislamiento de tenant** aplicado en el framework, nunca solo en el controlador.
2. **Autorización en cada endpoint**, denegando por defecto.
3. **Auditoría** de toda creación, modificación y borrado.
4. **Ningún literal** visible escrito en el código: cuatro idiomas (es, en, de, fr).
5. **Un módulo no importa** código interno de otro.
6. **Ningún requisito terminado** sin test que lo cubra y referencie su ID.
7. **Ningún módulo cerrado** sin su documentación actualizada.

Lista completa: sección 0.5 del documento de requisitos (`INV-001` a `INV-015`).

## Versiones de los documentos

| Documento | Versión |
|-----------|---------|
| `README.md` | 2.6.29 |
| `CLAUDE.md` | 2.7.2 |
| `ARCHITECTURE.md` | 2.5.6 |
| `PLAN-IMPLEMENTACION.md` | 2.3.11 |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` | 3.2.20 |
| `docs/SETUP-CLAUDE-CODE.md` | 1.3.0 |
| `docs/SETUP-ENTORNO.md` | 1.3.0 |
| `SYSADMIN.md` | 0.8.18 |
| `SECURITY.md` | 0.3.12 |
| `PRIVACY.md` | 0.3.7 |
| `RUNBOOK.md` | 0.3.11 |
| `CONTRIBUTING.md` | 0.3.2 |

Historial completo en `CHANGELOG.md`.

## Puesta en marcha

Sigue `docs/SETUP-ENTORNO.md` de principio a fin. En 2-3 horas tendrás WSL2, Podman, Claude Code y el repositorio funcionando.

---

## Ramas

- `main` — producción, solo merges desde `develop` con etiqueta de versión
- `develop` — integración
- `feature/REQ-XXX-...`, `fix/REQ-XXX-...`, `chore/...` — cuelgan de `develop` y se borran tras el merge

Formato de commit: `tipo(ámbito): descripción [REQ-XXX-NNN]`

---

## Bloqueantes actuales

| ID | Qué falta | Bloquea |
|----|-----------|---------|
| **H0** | Centro piloto comprometido y ficheros de exportación de su plataforma actual | Criterio de salida de fase 1 y `REQ-ONB-003` |
| `OPEN-11` | **Dónde se aloja el piloto.** El desarrollo va en WSL2 y no puede alojar datos reales | Hito H0 |
| `OPEN-07` | Entidad jurídica y contrato de encargado de tratamiento | Datos reales y facturación |
| `OPEN-08` | Dominio y DNS con API para certificado comodín | Multi-tenant, fase 0 |
| `OPEN-09` | Proveedor de correo transaccional | `REQ-AUTH`, `REQ-COM` |
| `OPEN-10` | Almacenamiento de copias en proveedor distinto | `REQ-BKP` |
