---
name: datos-personales
description: Reglas de protección de datos aplicadas al código. Úsala al modelar cualquier dato de personas, al publicar imágenes, al exportar, al borrar, y al integrar un servicio externo.
---

# Datos personales y consentimientos

Casi todos los datos de esta plataforma pertenecen a **menores de edad**. Un fallo aquí no es un error de software: es una infracción ante la AEPD y la pérdida del cliente.

Estas reglas son legales, no técnicas. No se deducen leyendo el código.

## Categorías de datos

| Categoría | Ejemplos | Tratamiento |
|-----------|----------|-------------|
| Identificativos | nombre, DNI, dirección, foto | Estándar |
| **Categoría especial** | salud, alergias, NEAE, discapacidad, convivencia | **Tabla separada, cifrado a nivel de campo, permiso propio, auditoría de lectura** |
| Económicos | cuentas, morosidad, becas | Acceso restringido a rol económico |
| Judiciales | custodia, protocolos de protección | Máxima restricción |

Nunca metas una alergia, un informe psicopedagógico o un parte de convivencia en la tabla general del alumno "por comodidad".

## Consentimiento de imagen (`INV-014`)

La regla que más se incumple en la práctica.

- Dos consentimientos **independientes**: portal web del centro y redes sociales.
- Tres estados: autorizo, no autorizo, **pendiente**. Pendiente equivale a no autorizado.
- **Antes de publicar cualquier imagen**, comprobar el consentimiento vigente de **todos** los alumnos identificables. Una foto de grupo requiere que todos lo tengan.
- La revocación tiene **efecto inmediato**: las imágenes afectadas dejan de ser accesibles al momento, no en el próximo despliegue.
- Aplica a la galería del aula, la web pública, los boletines, las circulares y cualquier exportación.

## Menores y tutela (`INV-008`)

- Todo dato de un menor necesita base legal registrada y, cuando proceda, consentimiento del tutor legal.
- Verifica la **relación de tutela vigente** antes de dar acceso, no solo que el usuario sea "una familia".
- Respeta las restricciones judiciales de custodia: un tutor puede tener acceso revocado a un alumno concreto.
- Cuidado con las funcionalidades dirigidas a estudiantes menores: comunicación, foros y contenido tienen restricciones adicionales.

## Autorizaciones de recogida (`ADR-032`)

**Una sola lista maestra**, en la unidad familiar (`REQ-FAM-UNIT-005`). Ningún módulo mantiene la suya.

- Transporte, comedor, extraescolares, aula matinal e Infantil **consumen** esa lista. Pueden restringir sobre ella; nunca ampliarla.
- Un tutor excluido por resolución judicial desaparece de la lista maestra y, por tanto, de **todos** los servicios a la vez. Ese es el motivo de que la lista sea única: revocar en un sitio y olvidarlo en otro tiene consecuencias sobre un menor.
- La revocación tiene efecto inmediato.
- La lista contiene datos personales **de terceros** (abuelos, personas de apoyo): su consulta se audita.

Si ves código que guarda personas autorizadas colgando de una suscripción a un servicio, es un fallo de diseño: debe referenciar la lista maestra.

## Borrado y retención (`ADR-004`)

Tres niveles distintos, y confundirlos es el error habitual:

| Nivel | Cuándo | Reversible |
|-------|--------|-----------|
| Borrado lógico | Operación normal del usuario | Sí |
| Anonimización | Derecho de supresión, conservando lo que la ley obliga a guardar | No |
| Purga física | Vencido el plazo legal de conservación | No |

**Un centro no puede borrar una factura ni un acta de evaluación aunque la familia lo pida**: existe obligación legal de conservación que prevalece sobre el derecho de supresión.

Toda entidad declara su plazo de retención y su base legal (`REQ-PRIV-006`).

Y al restaurar una copia: **los datos ya suprimidos no pueden reaparecer** (`REQ-BKP-005`).

## Exportaciones

Es la vía más común de fuga.

- Verifica permiso `exportar` con su ámbito.
- Registra en auditoría **qué** se exportó, no solo que hubo una exportación.
- Ejecución en cola con enlace de descarga caducable, nunca acceso público.
- Los entornos de desarrollo y pruebas usan **datos anonimizados** (`RSEC-GDPR-010`). Nunca una copia de producción.

## Servicios externos

Todo tercero que trate datos (pagos, firma, correo, SMS, almacenamiento, errores) es **encargado de tratamiento**: necesita contrato firmado, alojamiento en la UE y entrada en el registro de actividades (`REQ-PRIV-005`).

Antes de integrar un servicio nuevo, pregunta si va a recibir datos personales. Si la respuesta es sí, no es una decisión técnica: es una decisión que requiere contrato.

## Errores característicos

- Volcar el objeto completo del alumno en un log o en una traza de error.
- Poner el nombre del alumno en la URL o en el asunto de un correo.
- Enviar a un proveedor de errores una excepción con el payload entero.
- Usar datos reales en tests o en capturas de pantalla.
- Exponer identificadores secuenciales que permitan enumerar alumnos.
