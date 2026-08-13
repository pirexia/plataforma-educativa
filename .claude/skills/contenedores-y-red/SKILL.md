---
name: contenedores-y-red
description: Reglas de red, dependencias y reinicio de contenedores. Úsala al escribir o modificar compose.yaml, unidades Quadlet, configuración de Traefik o nginx, y al preparar cualquier despliegue o reinicio de servicios.
---

# Contenedores, red y dependencias

Estas reglas existen para evitar dos fallos concretos ya sufridos: **no poder reiniciar un servicio sin arrastrar a otro**, y **resolución de nombres rota tras recrear la pila** (`ADR-028`).

Causa común de ambos: un contenedor recibe IP nueva al recrearse, y cualquiera que la haya cacheado al arrancar apunta a un contenedor muerto.

## Regla 1 · El frontend no habla con el backend

La SPA es estática. **No hay `proxy_pass` desde el contenedor del frontend hacia la API.**

Traefik es el único punto de entrada y enruta por camino:

```
/api/*  →  contenedor api
/*      →  ficheros estáticos de la SPA
```

Quien llama a la API es el navegador, no el frontend. Sin relación entre ambos contenedores, reiniciar uno no puede afectar al otro.

Si alguien propone un nginx que haga de proxy hacia la API, recházalo y explica por qué.

## Regla 2 · Red externa, nunca destruida

```bash
# Una sola vez, fuera del ciclo de vida de la pila
podman network create --subnet 10.89.10.0/24 plataforma-net
```

```yaml
networks:
  plataforma-net:
    external: true
```

**Prohibido en el servidor:**

```bash
podman compose down        # destruye la red y rompe la resolución
```

**En su lugar:**

```bash
podman compose up -d --no-deps api      # recrea solo la API
podman compose restart api              # reinicio simple
systemctl restart plataforma-api        # con Quadlet
```

## Regla 3 · `Wants=`, nunca `Requires=`

En unidades Quadlet:

```ini
[Unit]
Description=API de la plataforma
After=plataforma-postgres.service plataforma-redis.service
Wants=plataforma-postgres.service plataforma-redis.service
# NUNCA: Requires= ni BindsTo=
```

| Directiva | Efecto |
|-----------|--------|
| `After=` | Ordena el arranque. Correcto. |
| `Wants=` | Preferencia sin propagar paradas. Correcto. |
| `Requires=` | Arrastra reinicios y paradas. **Es el origen del acoplamiento.** |
| `BindsTo=` | Aún más estricto. Nunca. |

La resiliencia se logra con **reintentos**, no con dependencias rígidas: si la API arranca antes que PostgreSQL, reintenta con espera exponencial en lugar de fallar.

## Regla 4 · Nunca cachear una IP

- Referencias entre servicios **solo por nombre de servicio**.
- **Ninguna IP de contenedor** en configuración, variables de entorno ni `.env`.
- Traefik descubre contenedores observando el socket de Podman, así que actualiza rutas solo cuando cambian las IPs.
- Ojo con los procesos de larga vida: los workers de colas mantienen conexiones abiertas a base de datos. Si PostgreSQL se reinicia, hay que reiniciar los workers. Es un reinicio consciente, no un acoplamiento.

## Regla 5 · Chequeos de salud en todo servicio

```ini
[Container]
HealthCmd=curl -fsS http://localhost:8080/health || exit 1
HealthInterval=10s
HealthRetries=3
HealthStartPeriod=30s
Notify=healthy
```

Un servicio está arrancado cuando su chequeo pasa, no cuando el proceso existe. Traefik solo debe enviar tráfico a contenedores sanos.

## Regla 6 · Reinicio de la API sin corte

En host único, **dos réplicas** del contenedor de API tras Traefik, recreadas de una en una. Mientras una se reinicia, la otra atiende. Es lo que hace cumplible `RARQ-DEP-001` sin orquestador.

## Antes de dar por buena una configuración

- [ ] ¿Hay algún `proxy_pass` o `upstream` apuntando a otro contenedor?
- [ ] ¿Aparece alguna IP escrita a mano?
- [ ] ¿Hay `Requires=` o `BindsTo=` entre servicios de aplicación?
- [ ] ¿La red está declarada como externa?
- [ ] ¿Algún script o runbook usa `compose down`?
- [ ] ¿Todos los servicios tienen chequeo de salud?
- [ ] ¿La aplicación reintenta si su base de datos no está lista?

## Prueba obligatoria antes de cada despliegue

Con la pila arrancada:

1. `systemctl restart plataforma-api` → el frontend **sigue sirviendo** y la API vuelve sola.
2. `systemctl restart plataforma-postgres` → la API reconecta sin intervención manual.
3. Recrear el contenedor de la API → Traefik enruta a la IP nueva sin reiniciar nada más.

Si alguna de las tres falla, la configuración está mal y no se despliega.
