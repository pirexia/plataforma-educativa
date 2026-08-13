# SETUP-CLAUDE-CODE.md

> **Versión 1.2.0** · 2026-08-11

Configuración del entorno de desarrollo asistido. Corresponde al **paso 0.2** de `PLAN-IMPLEMENTACION.md`.

> Los comandos y formatos de Claude Code evolucionan. Verifica con `/plugin`, `/agents` y `/mcp` antes de dar nada por hecho.

---

## 1. Qué hay en el repositorio

```
.claude/
├── agents/     9 subagentes con modelo asignado
├── skills/     10 skills propias del proyecto
└── settings.json
CLAUDE.md       normas permanentes
memory.md       estado entre sesiones
```

Todo esto se versiona **salvo** `.claude/settings.local.json`, que es personal.

---

## 2. Plugins recomendados

### 2.1 Oficiales de Laravel

Laravel mantiene una colección oficial de agent skills distribuida como plugins de Claude Code:

```
/plugin marketplace add laravel/agent-skills
/plugin install laravel@laravel
```

Aporta agentes y skills específicos del framework: convenciones, Eloquent, Artisan, migraciones y patrones de test. Es el único plugin de terceros que instalaría sin dudarlo, porque lo mantiene el propio equipo del framework.

**Laravel Boost** está también en el marketplace oficial de Anthropic: es un servidor MCP que da al agente conocimiento contextual de la aplicación Laravel (rutas, modelos, configuración, comandos de Artisan). Muy útil a partir de que el proyecto tenga estructura real; instálalo después del paso 0.4, no antes.

### 2.2 Del catálogo de tu organización

Dos encajan con requisitos concretos del proyecto:

| Plugin | Para qué |
|--------|----------|
| **Engineering** | Revisión de código, decisiones de arquitectura y documentación técnica. Complementa a `architect` y `doc-reviewer`. |
| **StackHawk HawkScan** | Análisis dinámico de seguridad. Cubre parte de `RSEC-PENT-001` y permite detectar problemas OWASP antes del pentest externo, que es caro y se contrata una vez. |

> **Buscado y no encontrado**: el catálogo no tiene ningún plugin de PostgreSQL ni de depuración. Lo más cercano es `cockroachdb`, que es otra base de datos, y `Data`, orientado a análisis y visualización, no a ingeniería de esquema. Por eso ambas áreas se cubren con skills propias.

### 2.3 Comunidad: con criterio

Existen marketplaces comunitarios con miles de skills, incluidos varios específicos de Laravel. Antes de instalar cualquiera:

> Un plugin no es una librería: son **instrucciones que el modelo va a seguir** y puede incluir hooks que ejecutan scripts en tu máquina. Un plugin malicioso puede exfiltrar el contenido de tus ficheros. Lee la descripción y el contenido antes de instalar, y prefiere fuentes identificables.

Y una regla práctica: **instala poco**. Demasiadas skills ralentizan el arranque de sesión y provocan activaciones falsas, que es peor que no tenerlas.

---

## 3. Servidores MCP

**Presupuesto**: menos de 10 servidores y menos de 30 herramientas activas. Cada servidor consume contexto y añade latencia. Con nuestra lista quedan 5.

### Se instalan

| MCP | Uso en el proyecto | Cuándo |
|-----|--------------------|--------|
| **GitHub** | Issues según la política de severidad de `CLAUDE.md`, PRs, ramas y logs de Actions | Paso 0.2 |
| **Context7** | Documentación versionada en tiempo real de Laravel, Vue, TanStack, Pest. Evita que el modelo invente APIs de versiones que no son las nuestras | Paso 0.2 |
| **Laravel Boost** | Contexto real de la aplicación: rutas, modelos, esquema, Artisan, logs | Tras el paso 0.4 |
| **Playwright** | Depuración visual de la SPA y capturas. Los tests se ejecutan por consola, no hace falta MCP para eso | Tras el paso 0.5 |
| **PostgreSQL** | Inspección de esquema y planes de ejecución | Tras el paso 0.8, **solo lectura y nunca contra producción** |

El de GitHub sostiene la gestión de incidencias: sin él, crear issues se vuelve manual y se abandona a la tercera sesión.

Sobre el de PostgreSQL: dar a un agente capacidad de ejecutar SQL contra una base con datos de alumnos es un riesgo real. Credenciales de **solo lectura** y apuntando a desarrollo o a una copia anonimizada. Nunca a producción.

### Se descartan, con motivo

| MCP | Por qué no |
|-----|-----------|
| **Filesystem** | Claude Code **ya lee y escribe ficheros de forma nativa**. Añadirlo duplica herramientas, consume contexto y crea ambigüedad sobre qué ruta aplica. |
| **Laravel Codebase MCP** | Lo que aporta ya lo cubren Boost y la búsqueda nativa. Es un tercero no verificado, y cada MCP adicional es superficie de cadena de suministro. |
| **Figma MCP** | Solo tiene sentido si el diseño nace en Figma. Con shadcn-vue el código de los componentes es nuestro y el design system se define en tokens, no se importa. |
| **Skills genéricas de Vue/TypeScript** | El modelo ya conoce bien la Composition API y TypeScript. Lo que falta no es teoría general, sino **la versión exacta** (lo resuelve Context7) y **nuestras convenciones** (lo resuelven nuestras skills). |

### Se aplazan

| MCP | Cuándo |
|-----|--------|
| **Sentry** | Fase 2, y como decisión consciente: las trazas de error pueden contener datos personales, así que el proveedor sería encargado de tratamiento y hay que configurar depuración de datos sensibles antes de enviarle nada (`REQ-PRIV-005`). |
| **Kubernetes** | Etapa E2, cuando exista clúster. |
| **`laravel/mcp`** | No es una herramienta de desarrollo: sirve para exponer **nuestra aplicación** como servidor MCP. Interesante como funcionalidad de producto dentro de `REQ-API`, no ahora. |

---

## 4. Skills propias del proyecto

Son más valiosas que cualquier skill genérica de framework, porque codifican **tus** decisiones:

| Skill | Se activa cuando |
|-------|------------------|
| `aislamiento-tenant` | Se escribe cualquier consulta, endpoint, job o test que toque datos de negocio |
| `modulo-nuevo` | Se inicia un `REQ-XXX` que no existe |
| `migracion-segura` | Se crea o revisa una migración |
| `i18n-cuatro-idiomas` | Se escribe texto visible, plantilla, correo o contenido editable |
| `contenedores-y-red` | Se toca `compose.yaml`, una unidad Quadlet, Traefik o se prepara un despliegue |
| `postgres-rendimiento` | Se crea o revisa una migración, se escribe una consulta de listado, o hay lentitud |
| `permisos-y-roles` | Se crea un endpoint, se definen permisos de un módulo o se revisa código que expone datos |
| `datos-personales` | Se modela un dato de personas, se publica una imagen, se exporta o se integra un servicio externo |
| `depuracion` | Cualquier error, test que falla sin motivo, o algo que funciona en local pero no en el servidor |
| `cierre-de-sesion` | Al arrancar y al cerrar cada sesión |

**Regla de contención**: con 10 skills propias más las del plugin de Laravel, el catálogo está lleno. **No se crea una skill nueva hasta haber corregido lo mismo tres veces.** Cada skill adicional ralentiza el arranque y compite por activarse; veinte ficheros que nadie lee son peores que ocho que se aplican.

Prevista pero **no escrita todavía**: `frontend-design-system` (convenciones de shadcn-vue, tokens, WCAG 2.2 AA, patrones de TanStack Table). Se escribirá en el paso 1.7, cuando existan convenciones reales que documentar.

---

## 5. Uso de los subagentes

| Subagente | Modelo | Cuándo invocarlo |
|-----------|--------|------------------|
| `spec-writer` | Opus | Al abrir un módulo nuevo, antes de implementar |
| `architect` | Opus | Dependencia nueva, cambio estructural, ADR |
| `implementer` | Sonnet | Implementación con especificación aprobada |
| `test-writer` | Sonnet | Tras implementar y al cerrar un issue |
| `security-reviewer` | Sonnet | **Antes de cada merge a `develop`** |
| `db-reviewer` | Sonnet | Cualquier cambio de esquema |
| `doc-reviewer` | Sonnet | Antes de cada merge y al cerrar fase |
| `explorer` | Haiku | Búsquedas e inventarios en el código |
| `janitor` | Haiku | `.gitignore`, formateo, limpieza de ramas |

**Gestión de cuota.** Opus consume rápido. El patrón que funciona con el plan Pro:

1. Sesión de **especificación** con Opus: `spec-writer` produce la spec del módulo. Se aprueba. Fin de sesión.
2. Sesiones de **implementación** con Sonnet: `implementer` y `test-writer` trabajan sobre la spec ya aprobada, que es lo que evita que Sonnet tenga que tomar decisiones de diseño.
3. Revisiones con Sonnet en subagente, que no consume el contexto principal.
4. Lo mecánico, a Haiku.

Delegar en subagente no es solo economía de cuota: mantiene limpio el contexto principal, que es el recurso que se agota antes en sesiones largas.

---

## 6. Orden de instalación

1. `/plugin marketplace add laravel/agent-skills` e instalar `laravel@laravel`
2. Configurar el MCP de GitHub y verificar creación de issues
3. Verificar que los 9 subagentes aparecen en `/agents`
4. Verificar que las 10 skills se activan en el contexto esperado
5. Tras el paso 0.4, añadir Laravel Boost ✅ 2026-08-14
6. Tras el paso 0.5, añadir Playwright ✅ 2026-08-14
7. Tras el paso 0.8, añadir el MCP de PostgreSQL
8. Antes del cierre de fase 1, evaluar HawkScan

> **Laravel Boost y Playwright** quedan declarados en `.mcp.json` (raíz del repo, versionado: no llevan secretos). Boost se instaló con `composer require laravel/boost --dev` en `apps/api` y `php artisan boost:install --mcp --no-interaction`; el instalador escribió el comando con `wsl.exe`, que sobra porque Claude Code ya corre dentro de WSL2 — se corrigió a mano. Un servidor MCP nuevo en `.mcp.json` no se activa en una sesión ya abierta: hace falta reconectar o abrir una sesión nueva (con el aviso de confianza del proyecto la primera vez).

---

## 7. Comprobación de que funciona

Antes de dar el paso 0.2 por cerrado:

- [ ] `/agents` lista los 9 subagentes con su modelo correcto
- [ ] Un subagente de Haiku no aparece usando Opus
- [ ] Se crea un issue de prueba en GitHub desde Claude Code y se cierra
- [ ] La skill `aislamiento-tenant` se activa al pedir una consulta a base de datos
- [ ] `memory.md` se actualiza correctamente al cerrar una sesión de prueba
- [ ] `.claude/settings.local.json` está en `.gitignore` y no se ha subido
