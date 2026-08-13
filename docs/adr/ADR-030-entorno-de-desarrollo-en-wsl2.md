# ADR-030 · Entorno de desarrollo en WSL2 y separación respecto al alojamiento

**Estado**: PROPUESTA
**Fecha**: 2026-08-11
**Sustituye para la etapa E0 a**: `ADR-027`
**Afecta a**: `ADR-024`, `RARQ-INF`, `OPEN-06`

## Contexto

La decisión previa situaba el desarrollo en una VM RHEL 10 sobre VMware. Se cambia a **WSL2 en el equipo personal** (Ryzen 7, 16 GB de RAM, SSD de 512 GB).

Esto resuelve un problema y crea otro. Resuelve `OPEN-06`: al desarrollar en equipo propio desaparece la duda sobre la titularidad de una infraestructura corporativa. Crea otro: **un portátil personal no puede alojar datos de alumnos reales bajo ningún concepto**, así que el alojamiento del piloto queda sin decidir.

## Decisión

### Separación explícita de dos entornos

| | Desarrollo | Piloto y producción |
|---|---|---|
| Dónde | WSL2 en equipo personal | Pendiente de decidir (`OPEN-11`) |
| Datos | **Exclusivamente sintéticos** (`REQ-SEED`) | Datos reales |
| Copias | Solo repositorio Git | Copias replicadas en proveedor distinto |
| Acceso | Personal | Controlado y auditado |

**Ningún dato real de alumno, familia o empleado toca este equipo.** Ni una exportación de GQdalya con datos reales, ni una copia de la base del piloto para depurar un fallo. Para eso está el generador de datos sintéticos.

### Distribución y runtime

- **RHEL 10 sobre WSL2** si hay imagen disponible con la suscripción; en su defecto, Fedora o Ubuntu.
- **Podman**, no Docker Desktop, para mantener paridad con el destino de producción y evitar que `compose.yaml` funcione en desarrollo y falle en el servidor.
- Ficheros `compose.yaml` idénticos a los de producción, con un fichero de sobreescritura para desarrollo.
- Las reglas de `ADR-028` sobre red y dependencias se aplican igual: son las que hacen que lo que funciona aquí funcione allí.

### Límites de recursos

Con 16 GB de RAM compartidos entre Windows y WSL2, la pila completa no cabe con holgura. Medidas obligatorias:

- Limitar WSL2 mediante `.wslconfig` a **10-11 GB** y dejar el resto a Windows.
- **Perfil de desarrollo reducido**: PostgreSQL, Redis, API y frontend en modo desarrollo. MinIO, servicio de PDF y workers se levantan solo cuando se prueban.
- Ajustar `shared_buffers` de PostgreSQL a valores de desarrollo, no a los de producción.
- El generador de datos sintéticos permite reducir el volumen: 300 alumnos para el día a día, y el volumen alto solo cuando se midan prestaciones.
- Los ficheros del proyecto viven en el **sistema de ficheros de Linux**, nunca en `/mnt/c`: el rendimiento entre sistemas de ficheros es malo y arruina la experiencia de desarrollo.

### Copias del entorno

El repositorio Git es la única copia que importa, y debe estar en remoto desde el primer día. Un equipo personal se pierde, se rompe o se reinstala.

## Consecuencias

- `OPEN-06` queda cerrada: la infraestructura de desarrollo es propia y sin datos reales.
- Se abre **`OPEN-11`**: dónde se aloja el piloto cuando llegue el centro. Es bloqueante para el hito H0 y debe decidirse antes, no después.
- `REQ-SEED` pasa a ser **MUST de fase 1**: sin datos sintéticos no se puede desarrollar, porque la alternativa (usar datos reales) queda prohibida.
- Las pruebas de rendimiento en este equipo son orientativas, no concluyentes. Las cifras de dimensionado de `ARCHITECTURE.md` se validan en el entorno de destino.
- La opción de la VM RHEL sobre VMware queda disponible como entorno de preproducción si la infraestructura resulta ser de titularidad adecuada.

## Alternativas descartadas

- **Docker Desktop**: licencia comercial según tamaño de empresa, y diverge del destino de producción.
- **Desarrollar directamente en Windows**: el stack es de Linux, y las diferencias de sistema de ficheros, permisos y saltos de línea generan fallos que no existen en producción.
- **Mantener la VM VMware como entorno único**: no resuelve la titularidad y añade dependencia de una infraestructura ajena para el trabajo diario.
