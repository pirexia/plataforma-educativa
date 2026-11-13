# REQ-SEED · Operación

Estado: PROPUESTO (2026-11-12). Los nombres de variables son propuestas; se fijan en `1.15b-a`.

## 1. Variables de entorno

| Variable | Por defecto | Descripción |
|---|---|---|
| `DEMO_SEED_PASSWORD` | sin valor | Contraseña común de las cuentas sintéticas (`RN-SEED-30`). En `local`/`testing`, opcional: sin ella se genera una aleatoria por ejecución y se muestra una vez. En cualquier otro entorno permitido, **obligatoria** y procedente del gestor de secretos. Nunca en el repositorio ni en `.env.example` con valor. Debe cumplir `PasswordPolicy` |
| `DEMO_SEED_BATCH_SIZE` | a calibrar en `1.15b-a` T2 | Elementos por lote (`RN-SEED-35`) |

**No existe** ninguna variable que habilite el generador en un entorno no permitido ni que relaje una guarda (`RN-SEED-02`). La lista de entornos permitidos es una constante del código, no configuración.

Variables existentes que condicionan su uso:

- `APP_ENV`: es lo que evalúa `RN-SEED-01`.
- `QUEUE_CONNECTION` y la cola `long`: `§4`.
- `CORS_ALLOWED_ORIGINS` y `VITE_API_URL`: el entorno de desarrollo está configurado para el slug `demo`. Para abrir en el navegador un centro `demo-…` hay que añadir su origen y apuntar la SPA a su subdominio (`OPEN-SEED-18`; valores exactos por comprobar, `funcional.md §15`).
- Almacén S3 (`ADR-076`): solo si un bloque genera ficheros (`OPEN-SEED-12`); en `1.15b` no se propone ninguno.

## 2. Servicios externos de los que depende

Ninguno fuera de la pila: PostgreSQL, la cola `database` y Redis para la caché de resolución de centro y de módulos. El generador no hace peticiones de red salientes (`RN-SEED-11`) y no envía correo (`RN-SEED-10`).

Si falta el trabajador de cola, la ejecución queda `pendiente` y el comando lo avisa. Si falta el almacén S3 y un bloque lo usa, los ficheros se omiten y se anota.

## 3. Despliegue

- **Producción**: el generador no se despliega (`§5.53`, `RN-SEED-04`). Lo único que llega a producción son las migraciones de `datos.md` (`tenants.is_synthetic`, siempre `false`, y las tablas `seed_*`, vacías). Motivo de no dejarlas fuera: el esquema debe ser idéntico en todos los entornos (`CLAUDE.md §9`).
- **`platform:sync-registry`**: **no cambia nada** en `modules`, `permissions` ni `feature_flags`, porque el generador no declara descriptor, permisos ni *flags* (`OPEN-SEED-01` = A). El bloque `centros` lo invoca antes de aprovisionar, igual que el precedente E2E, y siempre **después** de las guardas (`RN-SEED-07`).
- **Migraciones**: aditivas, compatibles con la versión anterior. Reversión: retirar las tablas `seed_*` y la columna dos versiones después, por el procedimiento habitual; el disparador de inmutabilidad se retira antes que la columna.
- **Trabajadores**: los lotes van a la cola `long`, que consume `worker-long`. Un lote encolado por una versión anterior del generador se ejecuta con la versión nueva solo si la `generator_version` de la ejecución coincide; si no, el lote se marca `fallido` y la ejecución se reinicia (no se mezclan versiones en una misma huella).
- **Acceso**: ejecutar el generador exige consola en el contenedor de la API. En un entorno compartido (staging, si se aprueba) ese acceso es el control; se documenta en `SYSADMIN.md` quién lo tiene.

### Orden de uso en desarrollo

1. Pila levantada con el perfil `worker` (o usar `--sync`).
2. Migraciones aplicadas.
3. Retirar los centros sin marca que hubiera (`§6`).
4. `demo:seed`; anotar la semilla y, si se generó, la contraseña.
5. `demo:status` hasta `completada`.

## 4. Tareas programadas y jobs

- **Tareas programadas**: ninguna. El generador nunca se ejecuta solo (`RN-SEED-05`).
- **Jobs**: un tipo de trabajo por lote de bloque, en la cola `long` (`retry_after` de 900 s). Cada trabajo lleva el identificador de la ejecución, el centro, el bloque y el número de lote; fija el contexto del centro al arrancar (regla transversal de `REQ-CORE/operacion.md`); repite las guardas (`RN-SEED-06`); no contiene datos personales ni la contraseña en su carga (la contraseña se lee de la variable en el momento, o viaja cifrada si es la generada en local: decisión del tramo, revisada por `security-reviewer`).
- **Módulos no contratados** (`RMOD-005`): el bloque de un módulo no contratado no encola nada.
- Las purgas programadas de los demás módulos actúan sobre los centros sintéticos como sobre cualquier otro.

## 5. Métricas y alertas

Sin alertas: no es un servicio. Lo que se registra por ejecución está en `seed_runs` y `seed_run_blocks`: duración total y por bloque, lotes, reintentos y recuentos. Sirve para calibrar el tamaño de lote y para saber cuánto tarda regenerar la demo.

### 5.1 Resultados de medición (`funcional.md §10`)

Se rellena al cerrar `1.15b-c`. Hasta entonces, **nada de esto está medido**.

| Medición | Fecha | Semilla | Versión | Filas relevantes | Mediana | p95 | Plan (resumen) | Cumple |
|---|---|---|---|---|---|---|---|---|
| `STATE_SQL` · listado, primera página | | | | | | | | |
| `STATE_SQL` · listado, última página | | | | | | | | |
| `STATE_SQL` · informe completo | | | | | | | | |
| `STATE_SQL` · `authorizedAmong` con todos los alumnos | | | | | | | | |
| `GET /users` | | | | | | | | |
| `GET /me` | | | | | | | | |

Las cifras del equipo de desarrollo son orientativas (`ADR-030`).

## 6. Problemas conocidos y diagnóstico

| Síntoma | Causa probable | Qué hacer |
|---|---|---|
| «Hay centros no sintéticos» en desarrollo | Un centro creado antes de `1.15b` (E2E antiguo, `demo` de escaparate) o a mano desde el backoffice | Retirarlo con su propio `teardown` o recrear la base. No hay forma de marcarlo después (`RN-SEED-09`), a propósito |
| La ejecución no avanza de `pendiente` | No hay `worker-long` en marcha (perfil reducido de `ADR-030`) | Levantar el perfil `worker` o usar `--sync` |
| Un lote se reintenta sin fin | Supera el `retry_after` de la cola | Bajar `DEMO_SEED_BATCH_SIZE` |
| «Slug ocupado» | Ejecución anterior sin purgar | `demo:purge` o recrear la base |
| Huella distinta con la misma semilla | Distinta versión del generador, distinto curso de referencia, o un bloque usa azar fuera de la semilla | Comparar `seed_runs`; si los parámetros coinciden, es un defecto (issue, Media: rompe `REQ-SEED-006`) |
| Una persona generada no se puede editar (`422` en el documento) | Es del grupo con `dni`/`nie` de dígito inválido (`OPEN-SEED-03`) | Es el comportamiento esperado; corregir el documento en la edición |
| El navegador no abre un centro `demo-…` | Origen no permitido o SPA apuntando a otro subdominio | `§1` |
| El generador se ejecuta a la vez que el E2E real | El E2E crea y destruye centros; ya marcados, no bloquean, pero compiten por la cola y el límite de accesos | No lanzarlos a la vez |

## 7. Seguridad operativa

- La contraseña generada en local se muestra una vez y no se guarda; si se pierde, se regenera el conjunto o se cambia por el flujo normal de la aplicación.
- La salida de `demo:seed` puede contener esa contraseña: no se pega en issues, PR ni registros de CI. En CI, las cuentas no necesitan contraseña conocida salvo en el E2E, que la lee de la salida en memoria como hace hoy.
- Un entorno accesible desde internet con cuentas sintéticas sigue siendo una superficie de ataque real (subida de ficheros, envío de correo, cuentas de administrador), aunque los datos sean falsos. Por eso `OPEN-SEED-05` no admite contraseñas publicadas.
- Los datos generados parecen reales a simple vista. La marca del centro y el nombre explícitamente ficticio son lo que evita que se traten como reales (`REQ-SEED-005`).

## 8. Impacto en copias de seguridad y restauración

- **Desarrollo**: no hay copias que importen (`ADR-030`: solo el repositorio). El conjunto se regenera con su semilla; esa es su «restauración».
- **Producción**: ninguno. Las tablas `seed_*` están vacías y `is_synthetic` es `false`.
- **Otros entornos permitidos**: un centro sintético entra en las copias como cualquier otro. No necesita política propia, pero no debe contarse como centro real en el panel de estado de copias (`REQ-BKP-004`) cuando exista: la marca permite excluirlo. Se anota para la especificación de `1.26`.
- Restaurar una copia que contenga centros sintéticos ya purgados los reintroduce; no es un problema de protección de datos (no son personales), solo de orden.

## 9. Reversión de la entrega

- `1.15b-a`: revertir el código; las migraciones se quedan (aditivas). Los centros generados se retiran recreando la base de desarrollo. El cambio en `e2e-real-tenant.php` es compatible hacia atrás solo con la columna presente.
- `1.15b-b`: revertir el código; sin esquema.
- `1.15b-c`: revertir el código; la purga no deja estado propio.

En producción no hay nada que revertir salvo las migraciones, que no tienen efecto funcional.
