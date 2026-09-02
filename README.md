# Plataforma de Gestión Educativa Multi-tenant

SaaS para la gestión integral de centros educativos. Segmento inicial: **centros concertados de la Comunidad de Madrid**, con primer ciclo de Educación Infantil en régimen privado.

| Campo | Valor |
|-------|-------|
| **Versión del documento** | 2.5.2 |
| **Fecha** | 2026-09-02 |
| **Estado del proyecto** | Fase 1 · MVP operativo. Bloque A (identidad y acceso): tenants/usuarios, autenticación local, sesiones activas, MFA (TOTP + correo), login con Google (fusión de cuentas) y SSO institucional OIDC con aprovisionamiento por emparejamiento cerrados y mezclados (`1.1`-`1.4b`). `1.4c` (SSO institucional SAML 2.0, `ADR-043`) en desarrollo, sobre rama propia, pendiente de revisión de seguridad y de mezclar |

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
| **`docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`** | Fuente de verdad funcional. 53 módulos, 43 ADR | Al empezar un módulo |
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
| Base de datos | PostgreSQL 17+ |
| Caché y colas | Redis + Horizon |
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
| `README.md` | 2.5.2 |
| `CLAUDE.md` | 2.3.0 |
| `ARCHITECTURE.md` | 2.0.2 |
| `PLAN-IMPLEMENTACION.md` | 2.2.0 |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` | 3.1.3 |
| `docs/SETUP-CLAUDE-CODE.md` | 1.2.0 |
| `docs/SETUP-ENTORNO.md` | 1.3.0 |
| `SYSADMIN.md` | 0.6.0 |
| `SECURITY.md` | 0.2.2 |
| `PRIVACY.md` | 0.2.2 |
| `RUNBOOK.md` | 0.2.0 |
| `CONTRIBUTING.md` | 0.1.0 |

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
