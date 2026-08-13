---
name: depuracion
description: Método de diagnóstico y catálogo de fallos característicos de este stack. Úsala ante cualquier error, comportamiento inesperado, test que falla sin motivo aparente o algo que funciona en local pero no en el servidor.
---

# Depuración

## Método

1. **Reproduce antes de tocar nada.** Si no puedes reproducirlo, no sabes si lo has arreglado.
2. **Lee el error entero**, incluida la traza y el `request_id` (`INV-013`), que correlaciona logs y trazas.
3. **Una hipótesis cada vez.** Cambiar tres cosas a la vez y que funcione no es depurar.
4. **Escribe el test que falla** antes del arreglo. Es el test de regresión que exige `CLAUDE.md`.
5. Si el fallo es de severidad media o superior, **issue en GitHub** con causa y solución.

Prohibido declarar algo arreglado sin haberlo ejecutado.

## Catálogo de fallos de este stack

Antes de investigar a fondo, descarta estos. Son los que se repiten.

### Permiso denegado incomprensible en un contenedor
**SELinux.** El volumen se montó sin la etiqueta `:Z`. Comprueba `ausearch -m avc -ts recent`. **No desactives SELinux**: añade la etiqueta.

### 502 después de recrear un contenedor
Alguien cacheó una IP. Revisa `ADR-028` y la skill `contenedores-y-red`. Si aparece un `proxy_pass` desde el frontend hacia la API, ese es el fallo de diseño.

### La consulta no devuelve nada y los datos existen
El scope de tenant está filtrando. Ocurre en jobs, comandos de consola y tareas programadas, donde no hay middleware que establezca el tenant. Compruébalo antes de sospechar de la consulta.

### El worker de colas usa código o configuración vieja
Los workers son procesos de larga vida: cargan el código al arrancar. Tras desplegar, hay que reiniciarlos. Lo mismo si PostgreSQL se reinicia: mantienen conexiones abiertas y no reconectan solos.

### El MFA por TOTP rechaza códigos correctos
Desfase de reloj en el servidor. `chronyc tracking`. Parece un fallo de código y nunca lo es.

### Un texto aparece en castellano estando en otro idioma
Clave de traducción sin añadir a los cuatro ficheros, o el idioma del destinatario no viajó en el payload del job que renderizó el documento (`i18n-cuatro-idiomas`).

### Funciona en local pero no en el servidor
Por orden de probabilidad: variable de entorno ausente, caché de configuración sin regenerar, SELinux, permisos de fichero, versión distinta del runtime.

### Un endpoint devuelve 403 y el permiso parece correcto
Recuerda que en usuarios con varios roles **deny sobrescribe allow** (`RPERM-007`). Usa la vista previa de permisos efectivos (`RPERM-009`).

### El test pasa solo o falla en conjunto
Estado compartido entre tests: base de datos sin limpiar, caché, o el tenant de un test filtrándose a otro. Los tests deben ser independientes y ejecutables en cualquier orden.

### Lentitud repentina en producción
Ve directo a `postgres-rendimiento`. Suele ser un índice que dejó de usarse, un N+1 recién introducido o una exportación ejecutándose contra el primario.

## Qué NO hacer

- Añadir `sleep` para "arreglar" una condición de carrera.
- Desactivar SELinux, la validación o un test que molesta.
- Ampliar un timeout sin entender por qué se agota.
- Ejecutar `podman system reset` o `migrate:fresh` para salir del paso. Están denegados en `settings.json` por este motivo.
