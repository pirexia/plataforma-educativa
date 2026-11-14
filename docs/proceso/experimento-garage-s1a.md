# Experimento Garage (ADR-076 S-1a, tramo 1)

> Fecha: 2026-11-11. Comprobación acotada de lo que `ADR-076 §10` declara no verificado. Datos sintéticos; credenciales generadas al vuelo y descartadas.

## Imagen

- `docker.io/dxflrs/garage:v2.5.0` existe (creada el 2026-10-24); `garage --version` → `v2.5.0`.
- Digest de la **lista de manifiestos** (multiarquitectura, el que se fija en `compose.yaml`): `sha256:677e5b758e4425a325b811c425c026bf35a4bf42f7ddbaa773de3f4fd779a5a5`.
- Digest del manifiesto `amd64` (el que muestra `podman image inspect .Digest`): `sha256:23b16d34e0348d9aca9b960a7429a7e6d494974b5288bfb8f6c3ab856895f71e`. No usar este en `compose.yaml`: rompería arm64.
- La imagen **no trae shell** (`sh` no existe): `init.sh` corre en el host e invoca `/garage` con `podman exec`.

## Configuración y variables de entorno

- `rpc_secret` y el token de administración se leen de `GARAGE_RPC_SECRET` y `GARAGE_ADMIN_TOKEN`: el nodo arranca con `garage.toml` sin secretos (comprobado).
- `s3_region = "garage"` en `garage.toml`; el cliente debe usar `AWS_DEFAULT_REGION=garage`.
- `garage status` sirve como `healthcheck` (código 0 con el nodo levantado, también sin layout).

## Flujo de init (comprobado)

1. `garage layout assign -z dc1 -c 1G <id16>` + `garage layout apply --version 1`.
2. `garage key import --yes -n <nombre> <id> <secreto>`: Garage exige id `GK` + 24 hex y secreto de 64 hex. **Repetirlo falla con 409 `KeyAlreadyExists`**: el script comprueba antes con `key info`.
3. `garage bucket create <bucket>` y `garage bucket allow --read --write --owner <bucket> --key <id>`.
4. Idempotencia: `infra/garage/init.sh` ejecutado dos veces seguidas contra el mismo nodo; la segunda no falla ni crea nada (`layout`, clave y bucket «ya existen, no se toca»). Con el volumen reutilizado tras recrear el contenedor, también.

## URL firmadas (SDK PHP del `api`, `request/response_checksum = when_required`, path-style)

Operación con `http://<contenedor>:3900`; firma con un cliente cuyo endpoint es `http://127.0.0.1:3900`; peticiones con `curl` desde el host.

| Caso | Resultado |
|---|---|
| URL firmada válida (caducidad 3 s), dentro de plazo | **200**, contenido correcto |
| Firma alterada | **403** |
| Clave cambiada a mano | **403** |
| Sin firma | **403** |
| URL caducada (tras 5 s) | **400** `InvalidRequest: Date is too old` (**no 403**) |
| Firmada con `http://<contenedor>:3900` y host reescrito a `127.0.0.1:3900` después | **403** (confirma el motivo del endpoint de firma, `ADR-076 §4`) |
| Firmada con `http://<contenedor>:3900` y usada contra ese host (desde otro contenedor) | 200 |

## Hallazgos

1. **Discrepancia con `ADR-076 §5` punto 4 y `§2.3`**: Garage responde **400**, no 403, a una URL caducada. El test de conformidad (tramo 3) debe aceptar «caducada → rechazada (400 o 403)» o fijar 400 para Garage; el proveedor real puede devolver 403. Hay que decidirlo en el tramo 3.
2. `podman network connect` no funciona con redes en modo `pasta` («invalid network mode»): para alcanzar Garage desde el `api` hay que crear el contenedor ya en `plataforma-net`.
3. La caducidad mínima es de segundos y los comandos de `podman exec` tardan: el test debe firmar y consumir en la misma ejecución.
4. No se probó el comportamiento con las sumas de verificación por defecto del SDK (sin `when_required`): fuera del experimento.

## Limpieza

Contenedor del experimento y contenedor `plataforma-garage` eliminados. **No se pudo borrar el volumen `plataforma-educativa_garage-data`** (el permiso de `podman volume rm` está denegado en la sesión): contiene solo datos sintéticos de las pruebas y puede borrarse con `podman volume rm plataforma-educativa_garage-data`.
