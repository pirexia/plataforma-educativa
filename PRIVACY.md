# PRIVACY.md

> **Versión 0.1.0** · 2026-08-18
> Documento vivo: se actualiza en cada fase (`CLAUDE.md` §6). Base del Registro de Actividades de Tratamiento (RAT) exigido por el RGPD — hoy es un **esqueleto**, no un RAT completo: varias secciones dependen de decisiones que todavía no se han tomado (`OPEN-07`, entidad jurídica y contrato de encargado de tratamiento). No se rellenan con suposiciones (`CLAUDE.md` §0/§11).

---

## 1. Estado actual: sin tratamiento real de datos personales

El desarrollo ocurre en un equipo personal bajo WSL2 y, por decisión explícita (`ADR-030`), **nunca aloja datos reales de alumnos, familias o personal**. Todos los datos usados en desarrollo y pruebas son sintéticos, generados por `REQ-SEED` con la convención de `REQ-SEED-005`: dominios `@example.com`, documentos de identidad con dígito de control inválido, centros con nombre explícitamente ficticio, nunca fotografías de personas reales.

Este documento describe el **marco de diseño ya decidido** para cuando exista tratamiento real, no un tratamiento que esté ocurriendo hoy.

## 2. Decisiones de diseño ya tomadas que afectan a la privacidad

| Decisión | Qué significa | Referencia |
|----------|----------------|------------|
| Minimización de datos personales | El modelo `Person` (identidad de alumnos, familias y personal) incluye solo el mínimo defendible: nombre, apellidos, fecha de nacimiento, documento, contacto, idioma. Fotografía, sexo, nacionalidad y dirección postal quedan fuera hasta que exista catálogo de bases legales por campo (`OPEN-13`) | `ADR-034` §1 |
| Borrado en tres niveles | Lógico (recuperable), anonimización (irreversible, conserva estructura estadística) y purga (eliminación física por retención cumplida) | `ADR-004` |
| Datos de categoría especial separados | Salud, NEAE y convivencia viven en tablas propias, cifradas, con permisos independientes y auditoría de lectura — nunca mezclados con el resto del expediente | `CLAUDE.md` §8 |
| Auditoría sin copia en claro de datos protegidos | El registro de auditoría (`audit_logs`) nunca guarda el valor de un campo de categoría especial, solo qué atributo cambió. Tampoco guarda el nombre del actor desnormalizado, para que la anonimización de una persona no deje un rastro legible en el histórico | `ADR-034` §3 |
| Datos de menores | Base legal y consentimiento del tutor registrados para todo dato de un menor, no asumidos | `INV-008` |
| Datos de prueba nunca reales | Bajo ningún concepto, ni una exportación del centro ni una copia de producción para depurar | `ADR-030` |

## 3. Registro de Actividades de Tratamiento (RAT) — plantilla

Se completa cuando exista entidad jurídica responsable del tratamiento (`OPEN-07`). Estructura prevista:

| Actividad de tratamiento | Finalidad | Base legal | Categorías de datos | Categorías de interesados | Destinatarios | Transferencias internacionales | Plazo de conservación | Medidas de seguridad |
|---|---|---|---|---|---|---|---|---|
| *Pendiente de `OPEN-07`* | | | | | | | | |

## 4. Procedimiento de derechos de las personas interesadas

Acceso, rectificación, supresión, portabilidad y oposición. **Pendiente de `OPEN-07`**: sin entidad jurídica ni Delegado de Protección de Datos (DPO) designado, no hay a quién dirigir ni quién resuelve una solicitud de ejercicio de derechos. Se documentará aquí el canal de contacto, el plazo de respuesta y el procedimiento interno en cuanto se resuelva.

## 5. Retención

Mínimo legal por tipo de dato y catálogo completo: responsabilidad de `REQ-PRIV-006` (no implementado todavía). Casos ya conocidos que necesitarán tratamiento específico:

- **Auditoría** (`audit_logs`): retención mínima de 2 años (`REQ-CORE-005`), append-only e inmutable por diseño. El conflicto con el derecho de supresión sobre identificadores personales queda resuelto por `ADR-035`: no se escribe su valor en `changes` (se redacta desde el origen, por política de modelo), así que no hay nada que suprimir dentro de la fila; la supresión se completa por vencimiento del plazo de retención. La purga automática en sí es responsabilidad de `REQ-PRIV-006`, todavía sin implementar. Detalle completo en `docs/adr/ADR-035-datos-personales-en-el-registro-de-auditoria.md`.
- **RCDS** (Registro Central de Delincuentes Sexuales, verificación obligatoria de personal en contacto con menores): plazo y base legal específicos de la normativa vigente, catalogar en `REQ-PRIV-006`.

## 6. Preguntas abiertas

- **`OPEN-12`** — **cerrada por `ADR-035`**: el derecho de supresión no se ejerce dentro de `audit_logs`; se evita que entre en la fila cualquier valor identificativo (ver sección 5) y la supresión se completa por retención. Queda pendiente de `REQ-PRIV-006` la ejecución real de la purga por vencimiento de plazo, exigible antes del primer dato real.
- **`OPEN-13`**: lista definitiva de columnas de `Person` y su base legal por campo, responsabilidad de `REQ-PRIV-006`.
- **`OPEN-07`**: entidad jurídica, encargado de tratamiento y DPO — bloquea las secciones 3 y 4 de este documento y la entrada de cualquier dato real.
