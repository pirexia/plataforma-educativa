# RUNBOOK.md

> **Versión 0.1.0** · 2026-08-18
> Documento vivo: se actualiza en cada fase (`CLAUDE.md` §6). Cubre por ahora únicamente el entorno de **desarrollo** en WSL2 (`ADR-030`) — no hay producción, piloto ni usuarios reales todavía. Los procedimientos de guardia, alertas y recuperación ante desastre de un entorno real se documentarán aquí cuando `OPEN-11` (alojamiento del piloto) se resuelva.

---

## 1. Clasificación de incidencias

Tabla completa y vinculante: `CLAUDE.md` §5. Resumen:

| Severidad | Acción |
|-----------|--------|
| Crítica (fuga entre tenants, exposición de datos personales, pérdida de datos, caída total, incumplimiento legal) | Issue en GitHub, **parar el trabajo en curso**, resolver de inmediato |
| Alta (fallo funcional que impide usar un módulo, vulnerabilidad explotable, migración destructiva) | Issue y resolución en la misma sesión |
| Media (rodeo posible, deuda que crecerá) | Issue y resolución en la misma sesión si no descarrila el objetivo |
| Baja (mejora, cosmético) | Issue documentado, sin resolver hasta que se pida |

## 2. Procedimientos de desarrollo (WSL2)

Referencia completa de arranque, `compose.yaml` y red: `SYSADMIN.md`.

### 2.1 Arrancar/parar el entorno

```bash
podman compose up -d          # perfil reducido: postgres, redis, api, web
podman compose --profile full up -d   # añade minio
podman compose ps
podman compose logs -f <servicio>
```

**Nunca** `podman compose down` salvo en un entorno completamente desechable — borra la red externa `plataforma-net` y rompe la resolución de nombres entre servicios (`ADR-028`, `SYSADMIN.md` §1.3).

### 2.2 Si un contenedor no arranca o no queda `healthy`

1. `podman compose logs <servicio>` primero, no adivinar.
2. Comprobar que la red `plataforma-net` existe: `podman network ls`.
3. Ver `docs/historial/0.7-nucleo-multitenant.md` y `docs/historial/0.8-modelo-de-datos-nucleo.md` — catálogo de bugs de entorno ya encontrados y su solución (deadlocks de test entre conexiones, `.env`/`storage/` ausentes en un *worktree* nuevo, purga agresiva de paquetes `-dev` en el `Containerfile`, etc.). No repetir un diagnóstico ya hecho.
4. **`api` sano un momento y `500`/`unhealthy` al siguiente, sin haber tocado nada del servicio a propósito** (issue [#62](https://github.com/pirexia/plataforma-educativa/issues/62), 2026-08-22): revisar `apps/api/.env` — `SESSION_LIFETIME` debe ser `>= AUTH_SESSION_TIMEOUT_MAX_MINUTES` (`SYSADMIN.md §2c`) desde `REQ-AUTH` (1.2). El código se interpreta por petición (sin *build*), así que un `git pull`/*merge* que traiga 1.2 sin ese valor ajustado tumba el contenedor en la siguiente petición. Si `podman rm`/`--force-recreate` falla con *"has dependent containers"* (acoplamiento `web` → `api` vía `depends_on`), no forzar más: `podman-compose down && podman-compose up -d` recrea la pila completa sin tocar la red externa (`external: true`) ni el volumen `postgres-data`.
5. Para cualquier otro síntoma: skill `depuracion` (método de diagnóstico y catálogo de fallos característicos del stack).

### 2.3 Si CI falla en un PR

- Revisar el check concreto (`ci-api.yml`, `ci-web.yml`, `dependency-scan.yml`) antes de reintentar sin más.
- Los ocho checks son *required status checks* en `develop` desde el cierre de 0.7 — un PR no se puede mezclar sin ellos en verde, y no se puede saltar con `--no-verify` ni equivalente.

### 2.4 Si `db-reviewer` o `security-reviewer` encuentran un hallazgo Crítico o Alto

Parar el merge. Documentar el hallazgo como issue de GitHub con severidad, ficheros implicados y propuesta de solución (`CLAUDE.md` §5). Un hallazgo Alta se corrige en la misma sesión antes de mezclar; uno Crítico detiene además cualquier otro trabajo en curso.

## 3. Guardias (on-call)

**No aplica todavía.** No hay entorno de producción, no hay usuarios reales, no hay SLA que cumplir. Esta sección se escribe cuando exista alojamiento del piloto (`OPEN-11`) y el primer centro real.

## 3b. Despliegue y reversión (`ADR-037`)

Escrito, y probado **en parte** en WSL2 — ver `SYSADMIN.md §6` para el detalle exacto de qué está verificado y qué no. La topología completa (red, cinco servicios, imágenes `prod` reales) y las tres pruebas de resiliencia obligatorias se ejecutaron de verdad con `compose.prodlike.yaml` (`SYSADMIN.md §6.3`). El ciclo de vida por unidades Quadlet reales (`install.sh`, `systemctl --user`) **no se ha podido ejecutar en este host concreto**: `~/.config/containers` pertenece a `root`, un problema de permisos preexistente sin relación con este paso (`SYSADMIN.md §6.2`) — se corrige con un `sudo chown` que esta sesión no puede ejecutar sin contraseña interactiva.

### 3b.1 Generar el fichero de secretos

`ADR-037 §7.2`: se genera, nunca se escribe a mano. **Dos ficheros, no uno** (issue #36, hallazgo de la revisión independiente de security-reviewer sobre 0.9b): `postgres.container` no debe ver `APP_KEY` ni las contraseñas de conexión de Laravel, así que tiene su propio fichero más pequeño.

```bash
# Producción/staging real (systemd de sistema):
sudo install -d -m 0755 /etc/plataforma
sudo install -m 0600 -o root -g root infra/quadlet/plataforma.env.example /etc/plataforma/plataforma.env
sudo install -m 0600 -o root -g root infra/quadlet/plataforma-postgres.env.example /etc/plataforma/plataforma-postgres.env
# Rellenar cada valor vacío con:
openssl rand -base64 32
# APP_KEY tiene su propio generador — no uses openssl para esta:
php artisan key:generate --show

# Prueba en WSL2 (systemd --user), ruta equivalente:
install -d -m 0755 ~/.config/plataforma
install -m 0600 infra/quadlet/plataforma.env.example ~/.config/plataforma/plataforma.env
install -m 0600 infra/quadlet/plataforma-postgres.env.example ~/.config/plataforma/plataforma-postgres.env
```

**Los pares `TENANCY_*_PASSWORD`/`DB_*_PASSWORD` no son secretos independientes — son el mismo valor visto por dos consumidores, en dos ficheros distintos** (el script de aprovisionamiento de PostgreSQL crea el rol con `TENANCY_APP_PASSWORD` en `plataforma-postgres.env`; Laravel se conecta con `DB_PASSWORD` en `plataforma.env`; tienen que coincidir carácter a carácter entre los dos ficheros). Genera **una vez** por rol y copia el mismo valor al nombre de variable correspondiente en cada fichero — tres llamadas a `openssl rand`, no seis:

```bash
APP_PW=$(openssl rand -base64 32)       # TENANCY_APP_PASSWORD y DB_PASSWORD
OWNER_PW=$(openssl rand -base64 32)     # TENANCY_OWNER_PASSWORD y DB_OWNER_PASSWORD
PLATFORM_PW=$(openssl rand -base64 32)  # TENANCY_PLATFORM_PASSWORD y DB_PLATFORM_PASSWORD
```

Bug propio encontrado probando este procedimiento (0.9b.5, `compose.prodlike.yaml`): la primera versión de `plataforma.env.example` no tenía `DB_CONNECTION`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` ni los pares `DB_OWNER_*`/`DB_PLATFORM_*` — sin `DB_CONNECTION=pgsql`, Laravel cae a SQLite por defecto (`config/database.php`), y la imagen `prod` no lleva ni `database.sqlite` ni `.env`, así que el fallo habría sido un arranque roto, no una degradación silenciosa — pero sigue siendo un despliegue que no funciona. Corregido en la plantilla.

`APP_KEY` se copia además a un sitio distinto de la copia de la base de datos (`ADR-037 §7.2` punto 4, obligatorio antes de `0.10d`) — sin ella, los datos de categoría especial cifrados son irrecuperables aunque la base de datos se restaure.

### 3b.2 Desplegar una versión

```bash
./infra/install.sh <tag>              # producción/staging real, systemd de sistema
./infra/install.sh <tag> --user       # WSL2, systemd de usuario
```

`<tag>` es siempre una versión exacta (`X.Y.Z` en producción, `sha-<7>` o `develop` en *staging*) — nunca `latest` (`ADR-037 §5.2`). El script sustituye el *tag* en las unidades y recarga systemd; no arranca nada por sí solo.

**Escrito, no ejecutado de extremo a extremo en este host** (`SYSADMIN.md §6.2`): `install.sh --user` requiere escribir en `~/.config/containers/systemd/`, bloqueado por el propietario incorrecto del directorio. La lógica que sí se ha ejercido — sustitución de `__TAG__`, generación de unidades systemd válidas a partir de estos ficheros — está verificada por separado (`SYSADMIN.md §6.1`); lo que falta es la instalación automática en este directorio concreto, no el contenido de las unidades.

### 3b.3 Reversión

Mismo mecanismo que el despliegue: bajar el *tag* a la versión anterior y reiniciar la unidad.

```bash
./infra/install.sh <tag-anterior> --user
systemctl --user restart api@1.service web.service
```

Es una operación de segundos porque cada versión es una imagen inmutable en GHCR referenciada por *tag* exacto (`ADR-037 §5.2`) — no hay migración de imagen que deshacer, solo qué proceso arranca. Las migraciones de base de datos son *expand/contract* (`RARQ-DEP-003`): el esquema de la versión anterior sigue siendo compatible, así que revertir el código no exige revertir el esquema.

**No probado de extremo a extremo por el mismo bloqueo de permisos que 3b.2.** Lo que sí se probó de verdad y ejercita el mismo mecanismo de fondo (sustituir la imagen que corre un contenedor sin romper el enrutado): la prueba C de `SYSADMIN.md §6.3` recreó el contenedor de la API con una nueva instancia y Traefik enrutó a la IP nueva sin intervención manual — es la misma propiedad que hace segura una reversión, aplicada a una recreación en vez de a un cambio de *tag*.

### 3b.4 Notas operativas de las unidades Quadlet

- **Nunca `systemctl stop plataforma.network`** salvo desmantelamiento completo: es la misma regla que `podman compose down` en desarrollo (`ADR-028 §2`), aplicada a la unidad de red de Quadlet.
- La segunda réplica de la API (`api@2`) existe como plantilla ya escrita pero **no se activa** hasta que haya tráfico real (`ADR-037 §6.4`): `systemctl --user enable --now api@2.service` cuando corresponda, sin cambios de fichero.
- El proxy de socket delante de Traefik (`ADR-037 §6.3`) es una tarea de `0.10e`, no de hoy: mientras tanto el socket de Podman está montado de solo lectura y el entorno no tiene datos reales.

## 4. Copias de seguridad y recuperación

**No aplica todavía.** El módulo `REQ-BKP` (copias de seguridad, restauración granular en cuatro niveles, copia inmutable) no está implementado, y el proveedor de almacenamiento de copias distinto del host sigue sin decidir (`OPEN-10`). No hay nada que respaldar en un entorno sin datos reales.

## 5. Referencias

- Diagnóstico general: skill `depuracion`.
- Catálogo de bugs ya encontrados y resueltos por paso: `docs/historial/`.
- Configuración de red y contenedores: `SYSADMIN.md`.
- Bloqueantes que impiden pasar de este runbook de desarrollo a uno de producción: `README.md` §"Bloqueantes actuales".
