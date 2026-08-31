# ARCHITECTURE.md — Arquitectura de la Plataforma de Gestión Educativa

| Campo | Valor |
|-------|-------|
| Versión | 2.0.2 |
| Fecha | 2026-08-11 |
| Estado | Propuesta cerrada, pendiente de ratificación |
| Documento de requisitos | `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` |

---

## 1. Visión general

Plataforma SaaS multi-tenant para la gestión integral de centros educativos. Monolito modular en backend con frontend desacoplado, empaquetado en contenedores y desplegable desde un único servidor hasta un clúster orquestado sin cambios en la aplicación.

**Segmento inicial**: centros concertados de la Comunidad de Madrid, con primer ciclo de Infantil en régimen privado. Complementa a Raíces/Roble, no lo sustituye (`ADR-016`).

---

## 2. Stack tecnológico

### 2.1 Decisión (`ADR-007` cerrado)

| Capa | Elección | Motivo principal |
|------|----------|------------------|
| **Backend** | Laravel, PHP 8.4+ | Resuelve de serie colas, permisos, MFA, i18n, generación documental y multi-tenancy. En solitario, cada pieza que no hay que ensamblar es una semana ganada. |
| **API** | REST + OpenAPI, versionada | `INV-006`, `REQ-API-001` |
| **Frontend** | Vue 3 + TypeScript + Vite, SPA | Separación real de capas, preparado para equipo, reutilizable en móvil |
| **UI** | Tailwind CSS + shadcn-vue (Reka UI) + TanStack Table | `ADR-023` |
| **Base de datos** | PostgreSQL 17+ | Único motor que cubre a la vez particionado por curso, seguridad a nivel de fila, full-text y PITR |
| **Caché y colas** | Redis + Horizon | `INV-012` |
| **Almacenamiento** | Compatible S3 (MinIO en desarrollo) | `ADR-013` |
| **Buscador** | Full-text nativo de PostgreSQL | `ADR-010` |
| **PDF** | Servicio contenerizado HTML→PDF | Plantillas en HTML reutilizando el design system |
| **Móvil** | PWA (fase 1) → Capacitor sobre la misma SPA (fase 3) | `ADR-008` |
| **Contenedores** | **Podman**; `compose.yaml` estándar en desarrollo, Quadlet/systemd en producción → Kubernetes | `ADR-003`, `ADR-024`, `ADR-027`, `ADR-030` |
| **CI/CD** | GitHub Actions | `RNF-MANT-004` |
| **Tests** | Pest (API), Vitest + Playwright (web) | `RNF-MANT-001` |
| **Observabilidad** | OpenTelemetry + stack de métricas, logs y trazas | `RARQ-CLOUD-003` |

### 2.2 Actualizabilidad del stack

Requisito explícito. Se garantiza con cuatro medidas:

1. **Runtime en contenedor**: actualizar PHP, Node o PostgreSQL es cambiar una etiqueta de imagen y validar en preproducción, no reinstalar servidores.
2. **Cadencia predecible**: Laravel publica una versión mayor al año con guía de actualización; PostgreSQL tiene 5 años de soporte por versión mayor.
3. **Actualización automatizada de dependencias**: Renovate o Dependabot con agrupación por tipo, actualizaciones menores automáticas si los tests pasan y mayores como PR revisada.
4. **Aislamiento de terceros**: pasarelas de pago, firma, SMS, almacenamiento y buscador van tras interfaces propias (`RNF-MANT-007`). Cambiar de proveedor no toca los módulos de negocio.

---

## 3. Estructura del repositorio

Monorepo con separación real de despliegue (`A3`).

```
/
├── apps/
│   ├── api/                    # Laravel
│   │   ├── app/Modules/        # un directorio por bounded context
│   │   │   ├── Core/ Auth/ Academico/ Calificaciones/ ...
│   │   │   └── <Modulo>/{Domain,Application,Infrastructure,Http}
│   │   └── tests/
│   └── web/                    # Vue 3 + TS
│       ├── src/components/ui/  # shadcn-vue (código propio)
│       ├── src/modules/        # espejo de los módulos de la API
│       └── tests/
├── infra/
│   ├── containers/             # Containerfiles por servicio
│   ├── compose/                # entornos dev, staging, prod
│   └── terraform/              # infraestructura como código
├── docs/
│   ├── REQUISITOS-PLATAFORMA-EDUCATIVA.md
│   ├── modulos/REQ-XXX/
│   ├── manual-usuario/
│   └── adr/
├── CLAUDE.md
├── memory.md
└── PLAN-IMPLEMENTACION.md
```

Cada módulo de `apps/api/app/Modules/` es un bounded context autónomo: no importa código interno de otro módulo (`INV-007`). La comunicación es por interfaces públicas o eventos de dominio.

---

## 4. Arquitectura de despliegue

### 4.1 Servicios

```
                    ┌──────────────┐
   Internet ───────►│ Reverse proxy│  TLS, cabeceras, rate limit
                    │  (Traefik)   │
                    └──────┬───────┘
                ┌──────────┴──────────┐
                ▼                     ▼
        ┌──────────────┐      ┌──────────────┐
        │  web (SPA)   │      │  api (PHP)   │  N réplicas
        │  estático    │      │              │
        └──────────────┘      └──────┬───────┘
                                     │
        ┌────────────┬───────────────┼───────────────┬────────────┐
        ▼            ▼               ▼               ▼            ▼
  ┌──────────┐ ┌──────────┐   ┌───────────┐   ┌──────────┐ ┌──────────┐
  │PostgreSQL│ │  Redis   │   │  workers  │   │ S3/MinIO │ │   PDF    │
  │          │ │caché+cola│   │  (colas)  │   │ ficheros │ │ servicio │
  └──────────┘ └──────────┘   └───────────┘   └──────────┘ └──────────┘
```

### 4.2 Evolución de la infraestructura (`ADR-024`)

| Etapa | Centros | Infraestructura |
|-------|---------|-----------------|
| **E0** | Desarrollo | **WSL2 en equipo personal** (Ryzen 7, 16 GB, SSD 512 GB), Podman, perfil reducido. Solo datos sintéticos (`ADR-030`) |
| **E0b** | 1 (piloto) | Host de alojamiento pendiente de decidir (`OPEN-11`). Datos reales, contrato de encargado de tratamiento, copias en proveedor distinto |
| **E1** | 1-3 | 2 VPS: aplicación + workers en uno, PostgreSQL y Redis en otro. Backups a segundo proveedor |
| **E2** | 4-10 | Kubernetes gestionado, réplica de lectura, almacenamiento de objetos gestionado, multi-AZ |
| **E3** | 11+ | Autoescalado horizontal, réplicas por zona, recuperación en segunda región |

La aplicación es **stateless** desde E0, de modo que el salto a E2 no requiere cambios de código.

### 4.3 Red y dependencias entre contenedores

Reglas derivadas de `ADR-028`, que resuelven dos fallos ya sufridos en despliegues anteriores: acoplamiento de reinicio entre servicios y resolución de nombres rota tras recrear la pila.

| Regla | Detalle |
|-------|---------|
| **El frontend no habla con el backend** | La SPA es estática. Traefik enruta `/api/*` a la API y `/*` a los ficheros estáticos. Quien llama a la API es el navegador. Sin relación entre ambos contenedores, reiniciar uno no afecta al otro. |
| **Red externa con subred fija** | Se crea una vez y no se destruye. `compose down` queda **prohibido** en el servidor: destruye la red y rompe la resolución. Para actuar sobre un servicio, `up -d --no-deps <servicio>`. |
| **`Wants=` + `After=`, nunca `Requires=`** | `Requires=` propaga paradas y reinicios: es el origen del acoplamiento. `After=` ordena el arranque y `Wants=` expresa preferencia sin arrastrar. |
| **Ninguna IP cacheada** | Referencias solo por nombre de servicio. Ninguna IP en configuración ni variables de entorno. Traefik observa el socket de Podman y actualiza rutas al vuelo. |
| **Reintentos, no dependencias rígidas** | Si la API arranca antes que PostgreSQL, reintenta con espera exponencial. |
| **Chequeo de salud en todo servicio** | Un servicio está arrancado cuando su chequeo pasa, no cuando el proceso existe. |
| **Dos réplicas de API** | En host único, tras Traefik y recreadas de una en una: es lo que hace cumplible `RARQ-DEP-001` sin orquestador. |

**Prueba obligatoria antes de cada despliegue**: reiniciar la API sin que caiga el frontend; reiniciar PostgreSQL y que la API reconecte sola; recrear la API y que Traefik enrute a la IP nueva sin tocar nada más. Si falla una, no se despliega.

### 4.4 Alta disponibilidad y portabilidad

- El SLA del 99,9% (`RNF-PERF-005`) se alcanza en E2 con multi-AZ dentro de un proveedor.
- **No se implementa multi-cloud activo-activo**: coste y complejidad desproporcionados. Lo que se garantiza es **portabilidad**: contenedores, infraestructura como código y ningún servicio propietario, de modo que levantar en otro proveedor sea cuestión de horas.
- Copias replicadas en un **segundo proveedor** dentro de la UE, con al menos una copia inmutable (`REQ-BKP-001`).

---

## 5. Seguridad de la arquitectura

- **Frontera única**: la API es el único punto donde se aplica la autorización. La SPA oculta opciones, no protege nada (`INV-002`).
- **Sesión por cookie** `httpOnly`, `Secure`, `SameSite=Lax` con CSRF, misma raíz de dominio. Prohibido JWT en almacenamiento del navegador (`ADR-025`).
- **Resolución de tenant por subdominio** en middleware previo a cualquier acceso a datos (`ADR-014`, implementado en `App\Http\Middleware\ResolveTenant`).
- **Aislamiento de tenant**: seguridad a nivel de fila en PostgreSQL (`FORCE ROW LEVEL SECURITY`) como **barrera primaria** — cubre SQL crudo, informes y acceso administrativo, no solo el ORM. El scope global de Eloquent (`App\Support\Tenancy\TenantModel`) es una capa de ergonomía secundaria: rellena `tenant_id` al crear y da semántica 404, pero RLS sigue en pie si se desactiva. Detalle completo de la implementación (tres roles de base de datos, claves foráneas compuestas, colas y caché conscientes de tenant) en `ADR-033`. Tests automáticos de acceso cruzado en cada pipeline (`RNF-MANT-006`, `tests/Feature/Tenancy/`).
- Datos de categoría especial en tablas separadas y cifradas a nivel de campo.
- Backoffice de plataforma en **dominio y aplicación separados**, con MFA obligatorio y lista blanca de IP (`REQ-BO-007`).
- **Modelo de datos núcleo**: `Person` (identidad) separada de `User` (credencial de acceso), para que una persona con varios papeles en el centro (p. ej. madre y docente a la vez) no se duplique en el censo. Auditoría (`INV-003`) mediante una tabla `audit_logs` única, polimórfica y append-only — inmutabilidad forzada con `REVOKE UPDATE, DELETE` a nivel de motor para **ambos** roles de aplicación (`plataforma_app` y `plataforma_platform`), no solo por convención de código. Catálogo de permisos y de módulos activables materializado desde el código por el comando idempotente `platform:sync-registry`, nunca mantenido a mano. Detalle completo en `ADR-034`.

---

## 6. Dimensionado de hardware

### 6.1 Supuestos de cálculo

- Un centro típico: 900 alumnos, 90 empleados, 1.400 tutores → **~2.400 usuarios**.
- Concurrencia real de pico: **8-12% de los usuarios** (paso de lista de 9:00 y publicación de notas).
- Almacenamiento: **2 GB base por tenant** + **30 MB por alumno y curso** (documentos, fotos, boletines, adjuntos).
- Base de datos: **~150 MB por 1.000 alumnos y curso**, más auditoría, que crece por separado y se archiva.

### 6.2 Tabla por tramos

| Tramo | Centros | Usuarios | Concurrentes pico | Documentos | Infra |
|-------|---------|----------|-------------------|-----------|-------|
| **T0** Desarrollo / piloto | 1 | ≤ 600 | ≤ 50 | ≤ 50 GB | 1 host |
| **T1** Producción inicial | 1-3 | ≤ 3.000 | ≤ 250 | ≤ 300 GB | 2 hosts |
| **T2** Crecimiento | 4-10 | ≤ 12.000 | ≤ 900 | ≤ 1,5 TB | Clúster |
| **T3** Escala | 11-30 | ≤ 35.000 | ≤ 2.500 | ≤ 5 TB | Clúster multi-AZ |

### 6.3 Recursos por componente

**T0 — un único host**

| Componente | vCPU | RAM | Disco |
|-----------|------|-----|-------|
| Todo (api, web, PostgreSQL, Redis, workers, MinIO, PDF) | 4 | 8 GB | 80 GB NVMe |

**Desarrollo — WSL2 en equipo personal**

| Recurso | Asignación |
|---------|-----------|
| RAM total del equipo | 16 GB |
| Límite de WSL2 en `.wslconfig` | 10-11 GB |
| Reservado a Windows | 5-6 GB |

Con ese margen, la pila completa no cabe con holgura. **Perfil reducido obligatorio**: PostgreSQL, Redis, API y frontend en desarrollo; MinIO, servicio de PDF y workers solo cuando se prueban. `shared_buffers` de PostgreSQL en valores de desarrollo. Volumen de datos sintéticos bajo (300 alumnos) para el día a día; el volumen alto solo para medir.

Los ficheros del proyecto residen en el **sistema de ficheros de Linux**, nunca en `/mnt/c`.

Las mediciones de rendimiento aquí son **orientativas, no concluyentes**: las cifras de dimensionado se validan en el entorno de destino.

**T1 — host único de piloto (pendiente de ubicación, `OPEN-11`)**

| Componente | vCPU | RAM | Disco |
|-----------|------|-----|-------|
| Host con todos los servicios en Podman | 4 | 16 GB | 160 GB NVMe |

Reparto orientativo en reposo: PostgreSQL ~2 GB, PHP-FPM ~1,5 GB, workers ~750 MB, servicio PDF ~1 GB en picos, Redis ~512 MB, MinIO ~512 MB, sistema y Podman ~1,1 GB. Las imágenes se construyen en CI, **nunca en el host**.

**T1 ampliado — dos hosts**

| Host | Componentes | vCPU | RAM | Disco |
|------|------------|------|-----|-------|
| Aplicación | api (2 réplicas), web, workers (2), PDF, proxy | 4 | 8 GB | 80 GB |
| Datos | PostgreSQL, Redis, almacenamiento de objetos | 4 | 16 GB | 300 GB NVMe |

**T2 — clúster**

| Componente | Réplicas | vCPU c/u | RAM c/u | Disco |
|-----------|----------|----------|---------|-------|
| api | 3 | 2 | 4 GB | — |
| workers | 3 | 2 | 4 GB | — |
| web (estático) | CDN | — | — | — |
| PostgreSQL primario | 1 | 8 | 32 GB | 1 TB NVMe |
| PostgreSQL réplica lectura | 1 | 4 | 16 GB | 1 TB NVMe |
| Redis | 1 | 2 | 8 GB | 20 GB |
| PDF | 2 | 2 | 4 GB | — |
| Objetos | gestionado | — | — | 1,5 TB |

**T3 — escala**

| Componente | Réplicas | vCPU c/u | RAM c/u | Disco |
|-----------|----------|----------|---------|-------|
| api | 4-8 (autoescalado) | 4 | 8 GB | — |
| workers | 4-8 | 4 | 8 GB | — |
| PostgreSQL primario | 1 | 16 | 64 GB | 4 TB NVMe |
| PostgreSQL réplicas | 2 | 8 | 32 GB | 4 TB |
| Redis | 3 (clúster) | 2 | 8 GB | 40 GB |
| PDF | 4 | 2 | 4 GB | — |
| Objetos | gestionado | — | — | 5 TB+ |

### 6.4 Reglas de dimensionado

- **RAM de PostgreSQL**: objetivo, que el conjunto de datos activo quepa en memoria. Aproximación: 4 GB base + 1 GB por cada 1.000 alumnos activos. `shared_buffers` al 25% de la RAM del host.
- **Workers**: el cuello de botella no es la web, son los picos de generación de PDF (boletines, facturas mensuales) y los envíos masivos. Dimensiona workers por el pico de cierre de mes, no por la carga media.
- **Disco**: NVMe siempre para la base de datos. Provisiona al doble del uso previsto a 12 meses.
- **CPU**: la generación de PDF es intensiva y a ráfagas. Aísla ese servicio para que no compita con la API.
- **Red**: cada centro descarga boletines y documentos en ventanas concentradas; verifica el tráfico incluido del proveedor.

### 6.5 Coste orientativo mensual

| Tramo | VPS europeo | AWS equivalente |
|-------|-------------|-----------------|
| T0 | 15-25 € | 60-90 $ |
| T1 | 45-80 € | 185-230 $ |
| T2 | 250-400 € | 900-1.400 $ |
| T3 | 800-1.200 € | 3.000-4.500 $ |

En AWS, vigilar el **NAT Gateway**: cuesta más que una instancia pequeña y suele quedar fuera de las estimaciones iniciales. Los planes de ahorro a un año recortan entre un 30% y un 40%.

---

## 7. Decisiones arquitectónicas nuevas

### ADR-023 · Librería de componentes de interfaz
**Decisión**: Tailwind CSS + shadcn-vue (sobre Reka UI) como design system único, con TanStack Table para las vistas de datos intensivas. Se descarta PrimeVue. 
**Motivo**: el branding por tenant (`RUX-BRAND-002`) se resuelve con variables CSS de forma trivial, mientras que el retematizado dinámico de PrimeVue es costoso; Reka UI aporta accesibilidad WCAG 2.2 AA por construcción; y el código de los componentes es propio, que es lo que permite el aspecto limpio y propio que exige `RUX-001`. 
**Consecuencia**: prohibido introducir una segunda librería de componentes. La rejilla de horarios (`REQ-ACAD-002`) se construye a medida con CSS Grid; si se evalúa una librería de calendario, revisar antes la licencia de las vistas de recursos.

### ADR-024 · Evolución de la infraestructura
**Decisión**: contenedores sobre un único host en E0-E1; Kubernetes gestionado a partir de 3-5 centros. Sin multi-cloud activo-activo. **Actualizado por `ADR-027`**: el host inicial es una VM RHEL 10 sobre VMware, no un VPS de proveedor público. `ADR-027` queda a su vez **sustituido para la etapa de desarrollo (E0) por `ADR-030`**: el host de desarrollo es WSL2 en equipo personal (ver tabla de §4.2); la VM VMware queda como candidata a entorno de preproducción.
**Motivo**: adoptar Kubernetes antes de tener producto consume semanas de operación que hacen falta en desarrollo. La aplicación stateless hace el salto barato cuando toque. 
**Consecuencia**: `RARQ-INF-006` se cumple a partir de la etapa E2. La portabilidad, no la redundancia simultánea, es la protección frente a la dependencia de proveedor.

### ADR-025 · Autenticación de la SPA
**Decisión**: sesión por cookie `httpOnly` con CSRF y mismo dominio raíz. Prohibido JWT en `localStorage` o `sessionStorage`. 
**Motivo**: con frontend y backend separados, la tentación es el token en almacenamiento del navegador, que convierte cualquier XSS en robo de sesión y contradice `RSEC-OWASP-003` y `RSEC-OWASP-006`. 
**Consecuencia**: la SPA se sirve bajo el mismo dominio raíz que la API por subdominio. Los clientes móviles y de terceros usan tokens de API con ámbito limitado, no la sesión web.

### ADR-027 · Plataforma de contenedores y host inicial
**Decisión**: el host inicial es una **VM RHEL 10 sobre VMware** (4 vCPU, 16 GB, 160 GB), con **Podman** como runtime de contenedores. Se mantienen ficheros `compose.yaml` estándar, ejecutados con `podman compose` en desarrollo y convertidos a unidades **Quadlet/systemd** antes de alojar datos reales. **Sustituido para la etapa de desarrollo (E0) por `ADR-030`**: ver más abajo.
**Motivo**: RHEL 10 no distribuye Docker y Docker CE no está soportado en esa plataforma. Podman es el runtime nativo, integra con systemd y SELinux, y permite mantener los mismos ficheros de composición. Quadlet aporta arranque ordenado, dependencias y reinicio automático, que en un host único sustituyen a lo que en Kubernetes daría el orquestador. 
**Consecuencia**: sustituye la referencia a VPS de proveedor público de `ADR-024` para el host inicial. El host no instala PHP, Node ni PostgreSQL: solo ejecuta contenedores. SELinux permanece en `enforcing` y los volúmenes se montan con `:Z`. Las imágenes se construyen en CI y el host solo las descarga.

**Estado tras `ADR-030` (`docs/adr/ADR-030-entorno-de-desarrollo-en-wsl2.md`)**: para la etapa **E0** (desarrollo), este ADR queda sustituido — el host de desarrollo es WSL2 en el equipo personal del desarrollador, no esta VM (ver tabla de §4.2). La VM VMware sigue vigente como candidata a entorno de **preproducción o piloto (E0b)** si su titularidad resulta adecuada.

**Riesgo abierto**: si la infraestructura VMware no es de titularidad propia, hay que resolver la titularidad del entorno y la figura de encargado de tratamiento **antes** de alojar datos de alumnos. Ver `OPEN-06`.

### ADR-026 · Estructura de documentación
**Decisión**: híbrida. Documentos raíz para lo transversal y un directorio por módulo con plantilla fija. 
**Motivo**: con 53 módulos, un documento único es inmanejable, provoca conflictos de merge constantes y consume el contexto del agente innecesariamente. La documentación por módulo permite cargar solo lo relevante. 
**Consecuencia**: norma obligatoria en `CLAUDE.md`. Ningún módulo se cierra sin su documentación; ninguna fase se cierra sin revisión por un subagente especializado.
