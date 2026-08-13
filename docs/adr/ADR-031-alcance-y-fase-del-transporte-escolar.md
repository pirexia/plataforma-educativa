# ADR-031 · Alcance y fase del módulo de transporte escolar

**Estado**: PROPUESTA
**Fecha**: 2026-08-11
**Afecta a**: `REQ-TRAN`, `REQ-FIN`, `REQ-FAM-UNIT`, `REQ-PRIV`

## Contexto

`REQ-TRAN` estaba definido como tres requisitos genéricos, prioridad COULD y fase 4. Al detallarlo aparecen dos hechos que invalidan esa clasificación.

**Es un servicio de pago recurrente.** Se factura mensualmente junto al comedor, admite altas y bajas a mitad de curso, descuentos por hermanos y subvenciones. Dejarlo para la fase 4 significa que los centros con ruta seguirían llevándolo en una hoja de cálculo mientras usan la plataforma para facturar todo lo demás.

**Es el módulo con mayor riesgo físico sobre menores** de toda la plataforma. Un error en calificaciones se corrige. Un menor entregado a quien no debía, o que no baja del vehículo, no.

## Decisión

- `REQ-TRAN` pasa de **COULD/fase 4** a **SHOULD/fase 2**, tras el módulo económico del que depende.
- El alcance se amplía de 3 a **12 requisitos**, incorporando explícitamente:
  - Autorizaciones de recogida y respeto de restricciones de custodia (`REQ-TRAN-005`).
  - Registro de subida y bajada con **alerta de discrepancia** (`REQ-TRAN-006`).
  - Acompañante de ruta y certificación negativa del RCDS con bloqueo, no aviso (`REQ-TRAN-003`).
  - Empresa de transporte como encargado de tratamiento, con minimización de los datos compartidos (`REQ-TRAN-001`).
  - Integración en la factura mensual con prorrateo, descuentos y subvenciones (`REQ-TRAN-009`).
- El seguimiento por GPS queda como `FUTURO` y **condicionado a evaluación de impacto en protección de datos**: geolocalizar menores no es una funcionalidad más.

## Motivo de las adiciones no solicitadas

Cuatro elementos no estaban en la petición original y se incorporan porque su ausencia sería un defecto grave:

1. **Autorizaciones de recogida.** Sin ellas, el sistema no sabe a quién puede entregarse un menor en la parada. Es el punto donde el módulo toca la custodia judicial.
2. **Alerta de subida sin bajada.** Es el mecanismo que detecta al menor que permanece en el vehículo. Sin registro cruzado, nadie se entera.
3. **Certificación negativa del RCDS con bloqueo.** Es exigencia legal para quien trabaja con menores. Un aviso que se puede ignorar no cumple.
4. **Empresa como encargado de tratamiento.** Compartir listados de alumnos con un tercero sin contrato firmado es una infracción, y ocurre por omisión.

## Consecuencias

- La fase 2 crece. Es asumible porque su orden ya priorizaba el bloque económico, del que el transporte es continuación natural.
- `REQ-FIN` debe contemplar líneas de servicio de terceros en la factura desde su diseño, no como añadido posterior.
- `REQ-SEED` genera rutas, paradas y suscripciones, de modo que el módulo sea demostrable con datos verosímiles.
- El listado de emergencia y la hoja de ruta contienen datos personales: requieren permiso propio y descarga auditada.
