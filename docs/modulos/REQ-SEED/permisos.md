# REQ-SEED · Permisos

Estado: PROPUESTO (2026-11-12).

## 1. Recursos que aporta el módulo

**Ninguno.** El generador no declara permisos (`declaredPermissions()` no existe: no es un módulo del catálogo, `OPEN-SEED-01`), no tiene recursos, ni ámbitos, ni resolutor, ni entrada en el mapa cerrado de `AR-10`. No hay *endpoints* que proteger (`api.md §1`).

Quién puede ejecutarlo no lo decide el sistema de autorización de la aplicación sino el acceso a la consola del contenedor de la API en un entorno permitido. Esa es una frontera de operación, no de permisos (`operacion.md §3`), y por eso las guardas de `funcional.md §4.1` no dependen de quién ejecuta.

### Ámbito restringido

No aplica.

## 2. Matriz recurso × acción × ámbito

No aplica: sin recursos.

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| — | — | — | — | — | — | — | — | — | — |

## 3. Roles que reciben las cuentas sintéticas

El generador **no crea roles ni concede permisos**: asigna a cada cuenta un rol predefinido ya existente, con los permisos que le dio el aprovisionamiento del centro. Nunca asigna por código de permiso ni comprueba roles en lógica de negocio.

| Cuenta sintética | Rol predefinido | Ámbito efectivo (el del rol) | Bloque |
|---|---|---|---|
| Administrador del centro | `administrador_centro` | El del rol | `centros` |
| Dirección | `direccion` | El del rol | `personal` |
| Secretaría y administración | `secretaria` | El del rol | `personal` |
| Docentes (tutores y especialistas) | `docente` | `grupo`, por su asignación docente | `personal`, `estructura` |
| Conserjería | `conserjeria_pas` | El del rol | `personal` |
| Tutores legales con cuenta | `tutor_legal` | `unidad_familiar` | `familias` |

Jefatura de estudios y orientación: solo si existen como roles predefinidos (`funcional.md §15`, punto 6); si no, no se inventan.

Reglas:

- **RN-SEED-34** Solo roles predefinidos. Un rol personalizado de demostración no está en el requisito y no se crea.
- Un mismo usuario con dos roles (docente que además es tutor legal de un alumno del centro, caso real de `RPERM-007`) es un caso de prueba valioso pero no está en `REQ-SEED`: no se genera salvo que el usuario lo pida.
- El tutor con el acceso revocado por restricción judicial (`RN-SEED-29`) conserva su cuenta y su rol: la revocación es del vínculo, no del rol (`REQ-FAM-UNIT-002`), y eso es lo que `CA-SEED-016` comprueba.

## 4. Datos de categoría especial

El generador **no crea datos de categoría especial en `1.15b`** (NEAE, salud y convivencia diferidos a sus bloques). Ninguna cuenta sintética recibe un permiso con `is_special_category` (`CA-SEED-044`). Cuando esos bloques existan, el permiso especial no irá en ningún rol por defecto tampoco en la demo: se asignará a una cuenta concreta y documentada, para que la demo no enseñe un comportamiento que producción no tiene.

## 5. MFA

El generador no cambia `mfa_obligatorio` de ningún rol. Las cuentas cuyo rol lo exige quedan obligadas igual que en producción; la única excepción propuesta es la exención temporal en `local`/`testing` (`RN-SEED-33`, `OPEN-SEED-09`), con motivo explícito de datos sintéticos, como hace el E2E.

## 6. Permisos de base de datos

No son permisos de aplicación, pero son lo que `security-reviewer` y `db-reviewer` deben mirar:

| Objeto | Rol de tenant | Rol de plataforma |
|---|---|---|
| `tenants.is_synthetic` | Lectura como el resto de `tenants`, si la tiene | Inserción; actualización rechazada por disparador |
| `seed_runs`, `seed_run_tenants` | Ninguno | Lectura y escritura |
| `seed_run_blocks` | Escritura restringida por RLS a su centro, si se elige la solución 1 de `datos.md §1.5`; si no, ninguno | Lectura y escritura |
| Tablas de negocio | Las de siempre, con RLS | El generador no las escribe por esta conexión (`RN-SEED-12`) |

La purga (`OPEN-SEED-04`) es la única operación que necesita más privilegio que estos, y por eso tiene sus propias condiciones (`RN-SEED-40` a `-43`).
