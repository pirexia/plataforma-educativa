---
name: modulo-nuevo
description: Estructura y pasos para crear un módulo (bounded context) nuevo en la API o en el frontend. Úsala al iniciar cualquier REQ-XXX que no exista todavía.
---

# Crear un módulo nuevo

## Estructura en la API

```
apps/api/app/Modules/<Modulo>/
├── Domain/          entidades, objetos de valor, eventos de dominio, interfaces
├── Application/     casos de uso, DTOs
├── Infrastructure/  repositorios, adaptadores, integraciones externas
├── Http/            controladores, requests, resources, rutas
├── Database/        migraciones, factories, seeders
└── Tests/
```

## Estructura en el frontend

```
apps/web/src/modules/<modulo>/
├── views/  components/  composables/  api/  types/  locales/
```

## Reglas de frontera (`INV-007`)

- Un módulo **no importa** clases internas de otro. Solo interfaces públicas expuestas en `Domain`.
- La comunicación entre módulos es por **eventos de dominio**. Ejemplo: `MatriculaCreada` la emite Alumnos y la consume Económico, que no conoce a Alumnos.
- Si dos módulos necesitan compartir mucho, probablemente son el mismo módulo o falta uno común. Plantéalo antes de acoplarlos.
- Prohibido consultar directamente tablas de otro módulo.

## Checklist de alta

- [ ] Especificación aprobada en `docs/modulos/REQ-XXX/`
- [ ] Registro del módulo en el catálogo de módulos activables (`RMOD-001`)
- [ ] Dependencias declaradas (`RMOD-006`)
- [ ] Permisos definidos: recurso, acciones y ámbitos
- [ ] Comportamiento con el módulo desactivado: oculto en la interfaz y 403 informativo en la API (`RMOD-008`, `RMOD-009`)
- [ ] Traducciones en los cuatro idiomas
- [ ] Tests, incluido el de aislamiento entre tenants
- [ ] Documentación de los cuatro ficheros del módulo
