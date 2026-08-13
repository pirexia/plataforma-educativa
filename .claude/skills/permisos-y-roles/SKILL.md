---
name: permisos-y-roles
description: Reglas del sistema de autorización granular. Úsala al crear cualquier endpoint, al definir permisos de un módulo, al tocar roles, y en toda revisión de código que exponga datos.
---

# Permisos y roles

`INV-002` es la segunda invariante más crítica, después del aislamiento de tenant. Un fallo aquí expone datos de menores dentro del propio colegio: un profesor viendo el expediente de un alumno que no es suyo, una familia accediendo a datos de otra.

## El modelo: recurso × acción × ámbito

Un permiso nunca es solo "puede ver calificaciones". Es **qué recurso**, **qué acción** y **sobre qué alcance**.

| Dimensión | Valores |
|-----------|---------|
| Recurso | alumnos, calificaciones, facturas, usuarios, horarios, documentos, incidencias, informes, configuración… |
| Acción | crear, leer, actualizar, eliminar, exportar, importar, aprobar, firmar, publicar |
| Ámbito | todos, propios, departamento, grupo, clase, unidad familiar |

Un docente tiene `calificaciones · actualizar · sus grupos`. No `calificaciones · actualizar · todos`. **El ámbito no es opcional**: omitirlo equivale a conceder acceso total.

## Reglas no negociables

1. **Denegar por defecto.** Todo permiso no concedido explícitamente está denegado (`RPERM-011`).
2. **Verificación en cada endpoint**, en el servidor. La interfaz oculta botones; eso no es seguridad (`INV-002`).
3. **Deny sobrescribe allow.** En usuarios con varios roles gana siempre lo más restrictivo (`RPERM-007`). Un profesor que además es padre de un alumno del centro es el caso real: no puede ver como profesor lo que no le corresponde, ni como padre lo que solo ve el claustro.
4. **Nadie concede lo que no tiene** (`RPERM-013`).
5. **Los datos de categoría especial van aparte.** Salud, NEAE y convivencia tienen permiso propio, **no incluido en ningún rol por defecto**, y auditoría de lectura, no solo de escritura (`RPERM-012`, `RPERM-015`).
6. **Los roles personalizados son ciudadanos de primera.** El administrador del centro crea roles propios. Toda regla que escribas debe funcionar sobre un rol que no existe todavía: nada de listas de roles codificadas.
7. **`mfa_obligatorio` es atributo del rol**, aplicable también a los personalizados. En multi-rol, si alguno lo exige, el usuario queda obligado (`REQ-AUTH-003`).

## Errores característicos

- **Comprobar el rol en lugar del permiso.** `if ($user->esDocente())` es deuda: en cuanto exista un rol personalizado con las mismas funciones, deja de funcionar. Comprueba siempre el permiso.
- **Verificar solo en el listado y no en el detalle.** El listado filtra bien y `GET /recurso/{id}` devuelve cualquier cosa. Es el IDOR clásico (`RSEC-OWASP-011`).
- **Olvidar `exportar`.** Es la acción que más datos mueve y la que más se olvida. Toda exportación verifica permiso, se audita con el detalle de lo exportado y se ejecuta en cola con enlace caducable.
- **Ignorar el ámbito en las relaciones.** El endpoint verifica que puede leer calificaciones, pero no que ese alumno sea de su grupo.
- **Confundir tenant con permiso.** Que el dato sea del mismo colegio no significa que este usuario pueda verlo. Son dos barreras distintas.

## Casos particulares de este dominio

- **Custodia**: un tutor puede tener el acceso revocado a un alumno concreto por resolución judicial, sin afectar al resto de la unidad familiar (`REQ-FAM-UNIT-002`).
- **Visibilidad diferida**: las calificaciones existen antes de ser visibles. Publicadas o no es una regla de negocio, no un permiso, y se comprueba además del permiso.
- **Permisos condicionales**: por ejemplo, solo durante el período de evaluación (`RPERM-008`).
- **Impersonation**: el soporte accede con banner visible, motivo, duración limitada y auditoría completa (`REQ-SUP-003`).

## Antes de cerrar un endpoint

- [ ] ¿Verifica permiso, recurso y **ámbito**?
- [ ] ¿Existe el mismo control en detalle, listado, exportación y actualización?
- [ ] ¿Hay tests de acceso denegado, no solo de acceso concedido?
- [ ] ¿Comprueba permisos y no roles?
- [ ] ¿Toca datos de categoría especial? Entonces permiso separado y auditoría de lectura.
