# ADR-028 · Topología de red y dependencias entre contenedores

**Estado**: PROPUESTA
**Fecha**: 2026-08-11
**Afecta a**: `RARQ-DEP-001`, `RARQ-DEP-007`, `RARQ-DEP-008`, `ADR-027`

## Contexto

En despliegues anteriores se han observado dos fallos recurrentes:

1. **Acoplamiento de reinicio**: no se puede reiniciar el backend sin parar también el frontend.
2. **IPs y DNS inconsistentes** tras un despliegue completo de la pila: servicios que apuntan a direcciones que ya no existen.

Ambos síntomas comparten causa. Un contenedor recibe una IP nueva cada vez que se recrea. Cualquier componente que haya **resuelto y cacheado** esa IP al arrancar seguirá apuntando al contenedor muerto hasta que se reinicie también. De ahí nace la sensación de que hay que reiniciar la pila entera.

El caso más común es un frontend con nginx que hace de proxy hacia la API: nginx resuelve el nombre del upstream **una sola vez, al cargar la configuración**, y lo cachea indefinidamente. El backend se recrea, cambia de IP, y nginx devuelve 502 para siempre.

El segundo detonante es destruir y recrear la red. Al eliminarla, la siguiente puede recibir otra subred, y los contenedores que no se recrean quedan aislados o con resolución obsoleta.

## Decisión

Cuatro medidas, en orden de importancia.

### 1. El frontend no habla con el backend

La SPA de Vue es un artefacto **estático**. No necesita proxy propio hacia la API.

El proxy inverso (Traefik) es el único punto de entrada y enruta por ruta:

- `/api/*` → contenedor de la API
- `/*` → ficheros estáticos de la SPA

El navegador del usuario es quien llama a la API, no el contenedor del frontend. **Esto elimina por completo la dependencia frontend → backend**, que es la que producía el síntoma 1. Reiniciar la API deja de tocar al frontend porque ya no existe relación entre ellos.

Cualquier propuesta de que el contenedor del frontend haga `proxy_pass` hacia la API se rechaza.

### 2. Red externa, preexistente y con subred fija

La red se crea **una vez**, fuera del ciclo de vida de la pila, con subred declarada, y no se destruye nunca en operación.

```bash
podman network create --subnet 10.89.10.0/24 plataforma-net
```

En `compose.yaml` se referencia como externa:

```yaml
networks:
  plataforma-net:
    external: true
```

**Prohibido `podman compose down` en el servidor**: destruye la red. Para actuar sobre un servicio concreto se usa `up -d --no-deps <servicio>`, que lo recrea sin tocar sus dependencias.

### 3. Dependencias con `Wants=`, nunca con `Requires=`

En producción los servicios se gestionan como unidades **Quadlet/systemd**. La diferencia entre directivas es exactamente el problema del usuario:

| Directiva | Efecto |
|-----------|--------|
| `Requires=` | Si la dependencia se detiene o reinicia, **arrastra** a la unidad dependiente. Es lo que provoca el acoplamiento. |
| `BindsTo=` | Aún más estricto. Peor. |
| `Wants=` + `After=` | Ordena el arranque **sin propagar** paradas ni reinicios. Es lo que queremos. |

Regla: `After=` para ordenar el arranque, `Wants=` para expresar preferencia, y **nunca** `Requires=` ni `BindsTo=` entre servicios de aplicación. La única excepción admisible es la dependencia de un servicio respecto a su propio volumen o red.

La resiliencia no se consigue con dependencias rígidas, sino con **reintentos**: si la API arranca antes que PostgreSQL, debe reintentar la conexión, no fallar.

### 4. Nunca resolver una IP una sola vez

- Toda referencia entre servicios se hace por **nombre de servicio**, jamás por IP.
- Prohibido escribir IPs de contenedor en ficheros de configuración, variables de entorno o `.env`.
- Si algún componente necesita hacer de proxy hacia otro contenedor, debe resolver el nombre **en cada petición**, no al arrancar.
- Traefik descubre los contenedores observando el socket de Podman, así que actualiza sus rutas automáticamente cuando un contenedor cambia de IP. Por eso es el único que debe enrutar.

## Consecuencias

- Reiniciar la API no afecta al frontend, ni al revés.
- Recrear cualquier contenedor no rompe la resolución de los demás.
- `podman compose down` queda prohibido en el servidor y documentado en `RUNBOOK.md`.
- Los servicios deben tolerar que sus dependencias no estén disponibles al arrancar, con reintentos y espera exponencial.
- Toda unidad declara `HealthCmd`, y el arranque se considera completo cuando el chequeo pasa, no cuando el proceso existe.
- Para despliegue sin corte de la API en host único, se ejecutan **dos réplicas** del contenedor de API tras Traefik, y se recrean de una en una.

## Alternativas descartadas

- **Fijar IPs estáticas a los contenedores**: parchea el síntoma, rompe el escalado y no sobrevive al salto a Kubernetes.
- **Reiniciar siempre la pila completa**: es precisamente el comportamiento que hay que eliminar, e incumple `RARQ-DEP-001`.
- **Mantener el proxy en el contenedor del frontend con resolución dinámica**: técnicamente posible mediante la directiva `resolver` y `proxy_pass` con variable, pero conserva un acoplamiento que en nuestra arquitectura no aporta nada.
