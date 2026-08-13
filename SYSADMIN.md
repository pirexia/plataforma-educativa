# SYSADMIN.md

> **Versión 0.3.0** · 2026-08-14
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

## 4. Pendiente de documentar aquí

- Alojamiento del piloto y producción (`OPEN-11`, bloqueante de H0).
- Quadlet/systemd para producción (`infra/`), cuando exista destino.
- Procedimiento de copia de seguridad (`REQ-BKP`, paso 1.26).
- Procedimiento de reversión de despliegue (sección 9 de `CLAUDE.md`).
