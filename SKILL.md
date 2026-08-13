---
name: aislamiento-tenant
description: Reglas y verificaciones para el aislamiento multi-tenant. Úsala siempre que se escriba una consulta, un endpoint, un job, un comando de consola o un test que toque datos de negocio. También al revisar código antes de mezclar.
---

# Aislamiento multi-tenant

La invariante `INV-001` es la más crítica del sistema. Una fuga entre tenants expone datos de menores de otro colegio y es un incidente de severidad crítica.

## Reglas

1. **El filtrado se aplica en el framework, no en el controlador.** Scope global obligatorio en el modelo base más seguridad a nivel de fila en PostgreSQL como segunda barrera.
2. **El `tenant_id` nunca viene del cliente.** Se resuelve del subdominio en un middleware previo a cualquier acceso a datos (`ADR-014`). Si aparece como parámetro de entrada, es un fallo.
3. **Los contextos sin petición HTTP son los peligrosos**: jobs en cola, comandos de consola, tareas programadas, listeners de eventos y seeders. Ahí no hay middleware. El tenant debe viajar explícitamente en el payload del job y establecerse al arrancar.
4. **Respuesta 404, no 403**, al pedir un recurso de otro tenant: no revelar existencia. Registrar siempre el intento.
5. **Ninguna consulta cruzada entre tenants** salvo en el backoffice de plataforma, que es una aplicación aparte y solo lee métricas agregadas.

## Qué revisar en cada cambio

- ¿Alguna consulta usa el constructor sin pasar por el modelo base?
- ¿Hay `whereRaw`, consultas nativas o vistas que se salten el scope?
- ¿Los jobs reciben y restauran el tenant?
- ¿Las relaciones cargadas con eager loading mantienen el filtro?
- ¿Las claves foráneas apuntan a registros del mismo tenant?
- ¿Los ficheros del almacenamiento están segregados por tenant en su ruta?

## Test obligatorio

Todo módulo nuevo incluye un test que crea dos tenants con datos equivalentes y verifica que, autenticado en el primero, ningún endpoint del módulo devuelve, modifica ni cuenta datos del segundo. Sin ese test, el módulo no está terminado.
