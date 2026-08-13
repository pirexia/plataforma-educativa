# PLAN-IMPLEMENTACION.md

> **Versión 2.2.0** · 2026-08-12

> Plan de ejecución dimensionado a **sesiones de ~5 horas** (límite del plan Pro). Cada paso cabe en una o dos sesiones y termina con el repositorio en estado compilable, tests en verde y `memory.md` actualizado.
>
> Marca el progreso aquí mismo: `[ ]` pendiente · `[~]` en curso · `[x]` terminado.

---

## Cómo usar este plan

- **Un paso = una sesión.** Si un paso no cabe, pártelo y anótalo.
- Antes de empezar: lee `CLAUDE.md` y `memory.md`.
- Los pasos marcados **[OPUS]** requieren especificación o diseño: úsalos al principio de la sesión, cuando tienes cuota.
- Los pasos **[SONNET]** son implementación. Los **[HAIKU]** son mecánicos y se delegan a subagentes.
- Ningún paso se cierra sin: tests, documentación del módulo, commit, `memory.md`.

---

## Aviso sobre el alcance

El documento tiene **52 módulos**. La fase 1 original tenía 14. En solitario y con cuota limitada, eso es más de un año antes de que un centro pueda usar nada.

Este plan recorta la fase 1 a **17 módulos**: el núcleo académico y de comunicación, más cuatro de plataforma que no son opcionales (`REQ-BO` porque sin backoffice no puedes operar, `REQ-BKP` porque sin copias no puedes alojar datos reales, `REQ-ONB` porque sin importador el centro no migra, y `REQ-CURSO` porque después obligaría a migrar el esquema).

> **Corrección**: una versión anterior de este plan afirmaba "9 módulos". Era incorrecto: los pasos listados suman 16. La cifra se ha corregido en lugar de recortar el alcance, porque los 16 son realmente el mínimo operativo. Lo que esto significa es que la estimación de la fase 1 es de 6-8 meses, no de 5.

---

# FASE 0 · Cimientos

> Objetivo: repositorio, entorno y esqueleto multi-tenant funcionando. Sin esto, todo lo demás genera deuda.

- [x] **0.1 · Repositorio y estructura** [HAIKU]
  Inicializar monorepo, ramas `main` y `develop`, `.gitignore`, estructura de directorios, `CLAUDE.md`, `memory.md`, licencia, `README.md` inicial. Primer commit.
  Cerrado 2026-08-13: `LICENSE` propietaria añadida, `.gitignore` con patrones de Python, limpieza de fichero suelto.

- [x] **0.2 · Configuración de Claude Code** [OPUS]
  Subagentes, skills propias y permisos **ya preparados** en `.claude/`. Guía en `docs/SETUP-CLAUDE-CODE.md`. Queda ejecutar la instalación de plugins y MCP y pasar la lista de comprobación de la sección 7 de esa guía.
  Cerrado 2026-08-13: MCP de GitHub y Context7 conectados y verificados (issue de prueba #1 creado y cerrado), plugin `laravel/agent-skills` instalado. **Pendiente sin resolver**: el subagente `spec-writer` no aparece en la lista de subagentes disponibles de la sesión pese a estar bien definido — anomalía del entorno, no del repositorio.

- [~] **0.3 · Entorno de desarrollo en WSL2** [SONNET]
  Distribución en WSL2, Podman, `compose.yaml` con api, web, PostgreSQL, Redis, MinIO y servicio de PDF. Límite de memoria en `.wslconfig` (10-11 GB) y **perfil reducido** que levante solo lo imprescindible. Proyecto en el sistema de ficheros de Linux, nunca en `/mnt/c`. Documentar en `SYSADMIN.md`. Ver `ADR-030`.
  Avance 2026-08-13: Podman, red externa `plataforma-net` y `.wslconfig` ya estaban operativos. `compose.yaml` creado con perfil reducido (`postgres`+`redis`+`api` por defecto, `minio` tras `--profile full`); los tres contenedores probados y en estado `healthy`. `SYSADMIN.md` creado. **Queda pendiente**: `web` (entra en 0.5) y el servicio de PDF (motor sin decidir, ver 1.17).

- [x] **0.4 · Esqueleto de la API** [SONNET]
  Laravel instalado, estructura modular por bounded contexts, autoload de módulos, healthcheck, OpenAPI vacío, Pest configurado.
  Cerrado 2026-08-13: Laravel 13 en `apps/api`, `app/Modules/` con autodescubrimiento de `ServiceProvider` por convención (`App\Support\Modules\ModuleServiceProviderDiscovery`, con test), `GET /api/health`, `openapi.yaml` con ese endpoint documentado, Pest (4 tests, 8 aserciones) y Larastan nivel 6 en verde. Contenedorizado: `infra/containers/api/Containerfile`, servicio `api` en `compose.yaml`, conexión real a PostgreSQL verificada desde dentro del contenedor. Sin módulos de negocio todavía (`app/Modules/` vacío hasta 1.1).

- [ ] **0.5 · Esqueleto del frontend** [SONNET]
  Vue 3 + TS + Vite, Tailwind, shadcn-vue inicializado, enrutado, layout base, cliente de API con manejo de errores, Vitest y Playwright configurados.

- [ ] **0.6 · CI/CD** [SONNET]
  GitHub Actions: build, tests, lint, análisis estático, escaneo de dependencias. Bloqueo de merge si algo falla. Renovate configurado.

- [ ] **0.7 · Núcleo multi-tenant** [OPUS + SONNET] ⚠️ *paso crítico*
  Resolución de tenant por subdominio, scope global obligatorio en el ORM, RLS en PostgreSQL como segunda barrera, y **tests automáticos de aislamiento** que fallen si un tenant alcanza datos de otro. `INV-001`, `RNF-MANT-006`.

- [ ] **0.8 · Modelo de datos núcleo** [OPUS]
  Migraciones de `Tenant`, `AcademicYear`, `Person`, `User`, `Role`, `Permission`, `AuditLog`, `ModuleSubscription`. Campos de auditoría, borrado lógico, `tenant_id` y `academic_year_id`. Sección 16 del documento de requisitos.

- [ ] **0.9 · Auditoría e i18n transversales** [SONNET]
  Registro automático de auditoría en el ciclo de vida del ORM. Sistema de traducción con los cuatro idiomas y detección de literales sin traducir.

- [ ] **0.10 · Decidir alojamiento del piloto** ⚠️ *`OPEN-11`, bloqueante del hito H0*
  El desarrollo va en WSL2 y **no puede alojar datos reales** (`ADR-030`). Hay que decidir dónde vivirá el piloto: VPS europeo, VM VMware si la titularidad resulta adecuada, u otra opción. Con contrato de encargado de tratamiento, datos en la UE y copias en proveedor distinto. Documentar en `SYSADMIN.md` y `RUNBOOK.md`.

- [ ] **0.10b · Dominio, DNS y certificados** [SONNET] ⚠️ *bloqueante no detectado antes*
  Registrar el dominio de la plataforma, configurar DNS con **comodín** (`*.dominio`) para la resolución de tenant por subdominio (`ADR-014`), y certificado comodín por reto DNS-01, que exige un proveedor de DNS con API. Sin esto, el multi-tenant no funciona ni en desarrollo.

- [ ] **0.10c · Correo transaccional** [SONNET] ⚠️ *bloqueante no detectado antes*
  Proveedor de envío, SPF, DKIM y DMARC. Sin esto no hay alta de usuarios, recuperación de contraseña, circulares ni notificaciones: es decir, no hay `REQ-AUTH` ni `REQ-COM`.

- [ ] **0.10d · Destino de copias de seguridad** [SONNET]
  Almacenamiento de objetos en **proveedor distinto** al del host, con una copia inmutable (`REQ-BKP-001`). Debe existir antes de que entre el primer dato real.

- [ ] **0.10e · Entorno de staging** [SONNET]
  Con un único host, staging convive con producción mediante separación de red y datos, o se levanta una segunda VM pequeña. `RARQ-CLOUD-005` pide cuatro entornos; documenta qué se cumple realmente y qué no.

- [x] **0.10f · Presentación comercial** [OPUS]
  Deck de 15 diapositivas con propuesta de valor, mapa de módulos, tres paquetes, comparativa y plan de implantación. En `marketing/`. **Pendiente antes de usarla**: fijar el nombre definitivo, validar los precios y sustituir los datos de contacto.

- [ ] **0.11b · Web publicitaria** [SONNET]
  Sitio público de captación: propuesta de valor, módulos por áreas, los tres paquetes con su comparativa, casos de uso por tipo de centro, formulario de contacto y solicitud de demostración. Estático (Astro o similar) con formulario a un servicio externo; **sin backend propio**, para no ampliar superficie de ataque antes de tiempo. Debe compartir tipografía y paleta con el producto (paso 1.7) y ser accesible según WCAG 2.2 AA. Requiere el dominio de `OPEN-08` y el nombre definitivo.

- [ ] **0.11c · Identidad de marca** ⚠️ *bloquea 0.11b*
  Fijar nombre definitivo, comprobar disponibilidad de dominio y de marca en la OEPM, y registrar el dominio. Logotipo y paleta aplicables a la web, la presentación y el producto.

- [ ] **0.11 · H0 comercial** ⚠️ *no técnico, bloqueante*
  Conseguir carta de intenciones del centro piloto y ficheros reales de exportación de su plataforma actual. Sin esto, `REQ-ONB-003` queda congelado.

- [ ] **0.12 · Marco legal del proveedor** ⚠️ *no técnico, bloqueante antes de datos reales*
  Titularidad de la infraestructura (`OPEN-06`), entidad que firma con el centro, **contrato de encargado de tratamiento** en el que tú eres el encargado y el colegio el responsable, y política de privacidad. Sin esto no se puede alojar ni un solo alumno real.

- [ ] **0.13 · Plantillas de documentación** [HAIKU]
  Crear `docs/modulos/_PLANTILLA/` y los documentos raíz que exige la sección 15: `README.md`, `SECURITY.md`, `PRIVACY.md`, `RUNBOOK.md`, `CHANGELOG.md`, `CONTRIBUTING.md`.

**Salida de fase 0**: aislamiento multi-tenant verificado por tests, pipeline en verde, entorno desplegado.

---

# FASE 1 · MVP operativo (9 módulos)

> Objetivo: un centro gestiona un trimestre completo sin sistema paralelo.

### Bloque A · Identidad y acceso

- [ ] **1.1 · `REQ-CORE`: tenants y usuarios** [OPUS + SONNET]
- [ ] **1.2 · `REQ-AUTH`: autenticación local y sesiones** [SONNET]
  Cookie de sesión con CSRF (`ADR-025`), política de contraseñas, bloqueo por intentos, recuperación.
- [ ] **1.3 · `REQ-AUTH`: MFA con obligatoriedad por rol** [SONNET]
  TOTP, códigos de respaldo, atributo `mfa_obligatorio` en la entidad rol, período de gracia, resolución restrictiva en multi-rol.
- [ ] **1.4 · `REQ-AUTH`: login con Google y fusión de cuentas** [SONNET]
- [ ] **1.5 · Permisos granulares** [OPUS + SONNET] ⚠️ *paso crítico*
  Matriz recurso × acción × ámbito, roles personalizados, denegación por defecto, vista previa de permisos efectivos. Sección 11.
- [ ] **1.6 · `REQ-BO`: backoffice de superadmin** [SONNET]
  Aplicación y dominio separados, ciclo de vida de tenants, matriz de módulos, MFA obligatorio, doble autorización para acciones destructivas.

### Bloque B · Design system y navegación

- [ ] **1.7 · Design system** [OPUS + SONNET]
  Tokens, tema por tenant con variables CSS, componentes base de shadcn-vue, validación de contraste, modo oscuro.
- [ ] **1.8 · Layout, navegación y dashboards por rol** [SONNET]
  Responsive con los breakpoints de `RUX-RESP-001`, menús adaptativos, estados vacíos y de error.
- [ ] **1.9 · Tablas de datos** [SONNET]
  TanStack Table con filtrado, ordenación, columnas configurables, virtualización y exportación.

### Bloque C · Estructura académica

- [ ] **1.10 · `REQ-CURSO`: ciclo de vida del curso** [OPUS + SONNET] ⚠️ *paso crítico*
  Es dimensión transversal: si se hace después, hay que migrar el esquema entero.
- [ ] **1.11 · `REQ-ACAD`: estructura académica** [SONNET]
- [ ] **1.12 · `REQ-ACAD`: horarios** [SONNET]
  Rejilla a medida con CSS Grid, detección de conflictos.
- [ ] **1.13 · `REQ-ACAD`: asistencia** [SONNET]
  El paso de lista es la operación más frecuente del sistema: optimizar para pocos toques y funcionamiento offline.

### Bloque D · Personas

- [ ] **1.14 · `REQ-FAM-UNIT`: unidad familiar y tutores** [OPUS + SONNET]
  Incluye custodia, restricciones de acceso por tutor y consentimiento de imagen granular.

- [ ] **1.14b · `REQ-FAM-UNIT-005` + `REQ-PRL-004`: autorizados y entrega de menores** [OPUS + SONNET] ⚠️ *paso crítico*
  **Lista maestra única** de personas autorizadas a recoger, con foto y documento, más el proceso de entrega en conserjería (`ADR-032`). La exclusión por restricción judicial debe propagarse a todos los servicios de forma automática. Adelantado de fase 3 porque es una operación diaria de todo el alumnado. Transporte, comedor y extraescolares consumirán esta lista; **ninguno mantiene la suya**.
- [ ] **1.15 · `REQ-ALUM`: expediente y matrícula** [SONNET]

- [ ] **1.15b · `REQ-SEED`: generador de datos de demostración** [SONNET] ⚠️ *habilitante*
  Tres centros ficticios (concertado con 0-3 privado, público y privado), entre 300 y 1.200 alumnos cada uno, plantilla completa de personal y datos operativos. Semilla reproducible y **bloqueo en producción sin excepción**. Sin esto no hay forma legítima de probar con volumen, porque usar datos reales está prohibido (`ADR-030`). Se amplía en cada bloque posterior conforme existan más módulos.

### Bloque E · Evaluación

- [ ] **1.16 · `REQ-CALIF`: calificaciones** [SONNET]
- [ ] **1.17 · `REQ-CALIF`: boletines en PDF** [SONNET]
  Branding por tenant, firma del tutor, publicación controlada, **emisión en el idioma del destinatario**.
- [ ] **1.18 · `REQ-INF`: primer ciclo de Infantil 0-3** [OPUS + SONNET]
  Evaluación cualitativa, informes de desarrollo, agenda diaria del aula, ratios.

### Bloque F · Comunicación y portales

- [ ] **1.19 · `REQ-COM`: mensajería, circulares y notificaciones** [SONNET]
- [ ] **1.20 · `REQ-AGENDA`: calendario escolar y agenda** [SONNET]
- [ ] **1.21 · `REQ-PROF`: portal del docente** [SONNET]
- [ ] **1.22 · `REQ-FAM-PORTAL`: portal de familias** [SONNET]
- [ ] **1.23 · `REQ-EST`: portal del estudiante** [SONNET]

### Bloque G · Puesta en marcha

- [ ] **1.24 · `REQ-ONB`: importador genérico** [SONNET]
  Mapeo visual de columnas, validación previa, ejecución reversible.
- [ ] **1.25 · `REQ-ONB`: perfil de migración desde GQdalya** [SONNET]
  Requiere los ficheros del paso 0.11.
- [ ] **1.26 · `REQ-BKP`: copias y restauración** [SONNET]
  PITR, copia por tenant, restauración granular, prueba de restauración automatizada.
- [ ] **1.27 · Endurecimiento y revisión OWASP** [OPUS]
  Repaso completo del Top 10 sobre lo construido, cabeceras, CSP, validación de ficheros.
- [ ] **1.28 · Revisión de documentación de fase** [SONNET, subagente `doc-reviewer`]
  Coherencia entre requisitos, código, API, permisos y manuales. Actualizar `SYSADMIN.md` y manuales de usuario.

**Salida de fase 1**: el centro piloto opera un trimestre real. Sin ese hito, no se abre la fase 2.

---

# FASE 2 · Cumplimiento y gestión

`REQ-FIN` · `REQ-BEC` · `REQ-DOC` · `REQ-AUT` · `REQ-OFE` · `REQ-RRHH` · `REQ-JOR` · `REQ-GUAR` · `REQ-CONV` · `REQ-NEAE` · `REQ-SALUD` · `REQ-SEC` · `REQ-PRL` · `REQ-PRIV` · `REQ-BI` · `REQ-API` · `REQ-SAAS` · `REQ-SUP` · `REQ-OPS`

`REQ-TRAN` (transporte escolar) entra también en esta fase (`ADR-031`).

Orden recomendado: económico primero (`FIN` → `BEC` → `TRAN`), porque es el diferenciador comercial frente a Raíces y el transporte se factura junto al resto; después cumplimiento legal (`JOR`, `CONV`, `PRIV`, `SALUD`); y por último el conector con Raíces (`SEC`), que es lo que elimina la doble grabación.

El transporte va inmediatamente después de `FIN` porque comparte la línea de facturación, y **antes** que el resto porque incluye control de acceso de menores: autorizaciones de recogida, restricciones de custodia y alerta de subida sin bajada.

**Salida**: pentest externo sin vulnerabilidades críticas ni altas. Go-live comercial.

---

# FASE 3 · Servicios y diferenciación

`REQ-EXTRA` · `REQ-COMED` · `REQ-ACOG` · `REQ-LIB` · `REQ-SHOP` · `REQ-WEB` · `REQ-NOM` · `REQ-ESP` · `REQ-PROV` · `REQ-GOB` · `REQ-ENC` · `REQ-VIDEO` · apps móviles con Capacitor.

# FASE 4 · Ampliación

`REQ-LMS` · `REQ-BIB` · `REQ-FCT` · `REQ-CRM` · predicciones de BI.

---

## Anexo A · Subagentes recomendados

| Subagente | Modelo | Función |
|-----------|--------|---------|
| `spec-writer` | Opus | Especificación funcional y técnica de un módulo antes de implementar |
| `architect` | Opus | Decisiones de diseño, ADR, revisión de impacto estructural |
| `implementer` | Sonnet | Implementación de un módulo acotado |
| `test-writer` | Sonnet | Tests que referencian IDs de requisito |
| `security-reviewer` | Sonnet | Revisión OWASP, aislamiento de tenant, permisos, datos especiales |
| `doc-reviewer` | Sonnet | Coherencia entre requisito, código, API y manual |
| `db-reviewer` | Sonnet | Revisión de migraciones: expand/contract, índices, bloqueos |
| `explorer` | Haiku | Búsquedas en el código, inventarios, listados |
| `janitor` | Haiku | `.gitignore`, formateo, limpieza de ramas, commits rutinarios |

Los subagentes de revisión (`security-reviewer`, `doc-reviewer`, `db-reviewer`) se ejecutan **antes de cada merge a `develop`**, no al final de la fase.

## Anexo B · Ritmo realista

Con una persona y sesiones limitadas:

| Fase | Pasos | Estimación |
|------|-------|-----------|
| 0 | 11 | 4-6 semanas |
| 1 | 28 | 5-7 meses |
| 2 | ~35 | 5-7 meses |
| 3 | ~30 | 4-6 meses |

Es una estimación optimista que asume dedicación constante. El mayor riesgo del proyecto no es técnico: es el agotamiento antes de tener el primer cliente. Por eso la fase 1 se recorta y el hito H0 es prioritario.
