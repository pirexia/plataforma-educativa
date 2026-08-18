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
4. Para cualquier otro síntoma: skill `depuracion` (método de diagnóstico y catálogo de fallos característicos del stack).

### 2.3 Si CI falla en un PR

- Revisar el check concreto (`ci-api.yml`, `ci-web.yml`, `dependency-scan.yml`) antes de reintentar sin más.
- Los ocho checks son *required status checks* en `develop` desde el cierre de 0.7 — un PR no se puede mezclar sin ellos en verde, y no se puede saltar con `--no-verify` ni equivalente.

### 2.4 Si `db-reviewer` o `security-reviewer` encuentran un hallazgo Crítico o Alto

Parar el merge. Documentar el hallazgo como issue de GitHub con severidad, ficheros implicados y propuesta de solución (`CLAUDE.md` §5). Un hallazgo Alta se corrige en la misma sesión antes de mezclar; uno Crítico detiene además cualquier otro trabajo en curso.

## 3. Guardias (on-call)

**No aplica todavía.** No hay entorno de producción, no hay usuarios reales, no hay SLA que cumplir. Esta sección se escribe cuando exista alojamiento del piloto (`OPEN-11`) y el primer centro real.

## 4. Copias de seguridad y recuperación

**No aplica todavía.** El módulo `REQ-BKP` (copias de seguridad, restauración granular en cuatro niveles, copia inmutable) no está implementado, y el proveedor de almacenamiento de copias distinto del host sigue sin decidir (`OPEN-10`). No hay nada que respaldar en un entorno sin datos reales.

## 5. Referencias

- Diagnóstico general: skill `depuracion`.
- Catálogo de bugs ya encontrados y resueltos por paso: `docs/historial/`.
- Configuración de red y contenedores: `SYSADMIN.md`.
- Bloqueantes que impiden pasar de este runbook de desarrollo a uno de producción: `README.md` §"Bloqueantes actuales".
