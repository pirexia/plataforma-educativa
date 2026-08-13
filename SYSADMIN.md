# SYSADMIN.md

> **Versión 0.4.0** · 2026-08-14
> Documento vivo: se actualiza en cada fase (`CLAUDE.md` sección 6), no solo al final. Cubre por ahora únicamente el entorno de **desarrollo** en WSL2 (`ADR-030`); el alojamiento del piloto y de producción se documentará aquí cuando `OPEN-11` se resuelva.

---

## 1. Entorno de desarrollo (WSL2)

Máquina personal, Ubuntu sobre WSL2. Ver `docs/SETUP-ENTORNO.md` para la puesta en marcha completa desde cero.

### 1.1 Límite de recursos

`.wslconfig` (en `%UserProfile%` de Windows):

```
[wsl2]
memory=10GB
processors=6
swap=4GB
```

### 1.2 Podman

Rootless, con socket de usuario persistente:

```bash
systemctl --user enable --now podman.socket
sudo loginctl enable-linger $USER   # los contenedores sobreviven al cierre de la terminal
```

Registros configurados en `~/.config/containers/registries.conf` (`docker.io`, `quay.io`, `ghcr.io`).

### 1.3 Red

Una única red externa, creada una vez y **nunca destruida** (`ADR-028`):

```bash
podman network create --subnet 10.89.10.0/24 plataforma-net
```

`podman compose down` está prohibido salvo en un entorno completamente desechable: borra la red y rompe la resolución de nombres entre servicios.

---

## 2. `compose.yaml`

Vive en la raíz del repositorio. Perfil reducido por defecto (`ADR-030`): solo lo imprescindible para trabajar a diario.

| Servicio | Perfil | Puerto (solo loopback) |
|----------|--------|------------------------|
| `postgres` (17) | por defecto | `127.0.0.1:5432` |
| `redis` (7) | por defecto | `127.0.0.1:6379` |
| `api` (Laravel, PHP 8.4) | por defecto | `127.0.0.1:8000` |
| `web` (Vue 3 + Vite) | por defecto | `127.0.0.1:5173` |
| `minio` | `full` | `127.0.0.1:9000` (API), `127.0.0.1:9001` (consola) |

`api` monta `apps/api/` como volumen (código en vivo, sin rebuild para cada cambio) y usa `apps/api/.env`, con `DB_HOST` y `REDIS_HOST` sobreescritos a los nombres de servicio (`postgres`, `redis`) porque dentro de la red de contenedores no valen `127.0.0.1`. Para Artisan desde el host (`php artisan migrate`, etc.) el `.env` sigue apuntando a `127.0.0.1` con los puertos publicados.

`web` monta `apps/web/` igual que `api` (mismo `node_modules` del host: WSL2 es Linux, no hay problema de binarios nativos entre host y contenedor). `apps/web/.env` apunta `VITE_API_URL` a `http://localhost:8000/api` **sin cambiarlo dentro del contenedor**: quien ejecuta ese `fetch` es el navegador en Windows, no el contenedor, así que necesita la URL publicada en loopback, no el nombre de servicio interno.

```bash
# Arranque diario
podman compose up -d

# Con almacenamiento de objetos, cuando se prueba subida de ficheros
podman compose --profile full up -d

# Estado
podman compose ps

# NUNCA: podman compose down (ver 1.3)
podman compose stop
```

Credenciales en `.env` (gitignored), a partir de `.env.example`.

**Pendiente, a propósito:**
- El servicio de renderizado HTML→PDF no está definido todavía: falta decidir el motor concreto (ver paso 1.17 del plan). No se ha fijado una dependencia sin esa decisión.
- Workers de Horizon se añaden cuando haya colas reales que procesar (a partir de 1.1).

---

## 3. Comprobación rápida

```bash
podman compose ps                                    # los cuatro servicios "healthy"
podman exec plataforma-postgres pg_isready -U plataforma
podman exec plataforma-redis redis-cli ping
curl -s http://127.0.0.1:8000/api/health
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:5173/
```

---

## 4. CI/CD (GitHub Actions)

Tres workflows en `.github/workflows/`, disparados por `push`/`pull_request` sobre `develop` y `main`, cada uno filtrado por ruta para no ejecutar la API en cambios solo de `apps/web` ni viceversa:

| Workflow | Jobs | Qué cubre |
|----------|------|-----------|
| `ci-api.yml` | `test`, `lint`, `static-analysis` | Pest (`composer test`), Pint (`composer lint`), Larastan nivel 6 (`composer analyse`). PHP 8.4, sin contenedor: runner nativo con `shivammathur/setup-php`. Los tests usan SQLite en memoria (`phpunit.xml`), no requieren PostgreSQL en CI. |
| `ci-web.yml` | `lint`, `typecheck-build`, `test`, `e2e` | ESLint, `vue-tsc -b` + build de Vite, Vitest, Playwright (Chromium, instalado con `--with-deps` en el propio job). El test e2e no depende de la API real: `HomeView` degrada a un mensaje de error visible si la petición falla, que es lo que el test comprueba. |
| `dependency-review.yml` | `dependency-review` | `actions/dependency-review-action` sobre el diff de cada PR, contra la base de datos de asesorías de GitHub (la misma que usa Dependabot). Falla en severidad `high` o superior. |

**Pendiente de configurar manualmente** (no automatizable desde una sesión de Claude Code, requiere al propietario del repositorio):

1. **Branch protection** en `develop` y `main`: marcar como *required status checks* los jobs `test`, `lint`, `static-analysis` (API), `lint`, `typecheck-build`, `test`, `e2e` (Web) y `dependency-review`. Sin esto los workflows se ejecutan pero no bloquean el merge.
2. **Dependabot alerts**: activar en Settings → Security → *Dependabot alerts* para ver vulnerabilidades en dependencias ya mezcladas (el workflow de arriba solo cubre las que entran en un PR nuevo).
3. **Renovate**: instalar la GitHub App desde `github.com/apps/renovate` sobre este repositorio. La configuración ya está en `renovate.json` (raíz): agrupa por `apps/api`/`apps/web`, ejecución semanal los lunes, sin automerge, alertas de vulnerabilidad con prioridad inmediata.

## 5. Pendiente de documentar aquí

- Alojamiento del piloto y producción (`OPEN-11`, bloqueante de H0).
- Quadlet/systemd para producción (`infra/`), cuando exista destino.
- Procedimiento de copia de seguridad (`REQ-BKP`, paso 1.26).
- Procedimiento de reversión de despliegue (sección 9 de `CLAUDE.md`).
