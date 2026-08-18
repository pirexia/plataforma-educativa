# SECURITY.md

> **Versión 0.1.0** · 2026-08-18
> Documento vivo: se actualiza en cada fase (`CLAUDE.md` §6), no solo al final. Cubre por ahora un producto en **fase 0**, sin datos reales (`ADR-030`) y sin endpoints HTTP de negocio todavía (`app/Modules/` vacío hasta el paso 1.1).

---

## 1. Reporte de vulnerabilidades

El repositorio es privado y el producto no tiene todavía despliegue público (`OPEN-11` sin resolver). Mientras tanto:

- Repórtalo a través de **GitHub Security Advisories** en este repositorio (`pirexia/plataforma-educativa`), o directamente a `pirexia` (propietario del repositorio).
- No abras un issue público para una vulnerabilidad explotable — usa el canal privado de *Security Advisories*.

Un contacto de seguridad dedicado (email, política de divulgación responsable con plazos) se publicará aquí en cuanto exista dominio propio (`OPEN-08`) y entidad jurídica (`OPEN-07`).

## 2. Arquitectura de seguridad ya implementada

| Control | Cómo | Referencia |
|---------|------|------------|
| Aislamiento entre tenants | RLS de PostgreSQL (`FORCE ROW LEVEL SECURITY` + política estándar) como barrera primaria; *scope* de Eloquent como ergonomía secundaria. Tres roles de PostgreSQL sin `SUPERUSER`, cada uno con los privilegios mínimos que necesita | `ADR-033`, `INV-001` |
| Autenticación de la SPA | Cookie de sesión `httpOnly`, `Secure`, `SameSite`, con CSRF. Prohibido JWT en `localStorage` bajo cualquier circunstancia | `ADR-025` |
| Auditoría | Tabla `audit_logs` polimórfica, append-only, con `REVOKE UPDATE, DELETE` a nivel de motor para todos los roles de aplicación — inmutabilidad forzada, no por convención. Registro automático desde el ciclo de vida del ORM (`created`/`updated`/`deleted`/`restored`), implementado en el paso 0.9: ningún otro código de la aplicación serializa un atributo hacia esta tabla | `ADR-034` §3, `ADR-035`, paso 0.9 |
| Redacción de identificadores personales en auditoría | `changes` nunca contiene el valor de un secreto (contraseñas, *tokens*, semillas TOTP), de un atributo de categoría especial, ni de un identificador personal fuera de la lista de inclusión explícita del modelo (`Selective`, falla en cerrado). Solo se registra qué atributo cambió y si pasó de vacío a lleno. Ver `PRIVACY.md` §5 para el porqué | `ADR-035` |
| Datos de categoría especial (salud, NEAE, convivencia) | Se modelan en tablas separadas, cifradas, con permisos propios y auditoría de lectura. La política de redacción de `audit_logs` nunca registra el valor de un campo de categoría especial, solo qué atributo cambió | `CLAUDE.md` §8, `ADR-034` §3, `ADR-035` |
| Secretos | Fuera del código, en gestor de secretos (o variables de entorno no versionadas en desarrollo). Ninguna credencial en el histórico de git | `CLAUDE.md` §8 |
| Escaneo de dependencias | Trivy en cada PR (`dependency-scan.yml`), Renovate para actualizaciones | `CI/CD`, paso 0.6 |
| Revisión de seguridad | Subagente `security-reviewer` obligatorio antes de cada merge a `develop`, contra OWASP Top 10, aislamiento de tenant, permisos y datos de categoría especial. Clasifica hallazgos por severidad (`CLAUDE.md` §5) y bloquea el merge si algo es crítico | `CLAUDE.md` §6 |
| Consultas a base de datos | Parametrizadas siempre. Nunca concatenación de SQL | `CLAUDE.md` §8 |
| Ficheros subidos | Validación de tipo real, tamaño y almacenamiento fuera de la raíz web, con URL firmada de caducidad corta | `CLAUDE.md` §8 |

## 3. Qué falta todavía (no es una omisión, es el orden del plan)

- **Autenticación de usuarios y MFA** (`REQ-AUTH`): no implementada hasta el paso 1.2. Hoy no hay flujo de login, recuperación de contraseña ni sesión de usuario real.
- **Autorización granular por endpoint**: el esquema de `roles`/`permissions` existe desde el paso 0.8, pero el resolutor que decide qué puede hacer cada usuario llega en el paso 1.5. Hasta entonces no hay ningún endpoint HTTP de negocio que proteger (`app/Modules/` vacío).
- **Cabeceras de seguridad y CSP estricta**: se configuran en el primer despliegue real (`OPEN-11`), no en desarrollo.
- **Hallazgos de seguridad conocidos y ya registrados**, no bloqueantes en fase 0: [#6](https://github.com/pirexia/plataforma-educativa/issues/6) (`TenantContext::runAsPlatform()` sin auditoría, pendiente del sistema de permisos), [#7](https://github.com/pirexia/plataforma-educativa/issues/7) (ventana de caché en la suspensión de tenants), [#8](https://github.com/pirexia/plataforma-educativa/issues/8) (cookie de sesión host-only por defecto de Laravel, sin refuerzo activo), [#18](https://github.com/pirexia/plataforma-educativa/issues/18) (falta un `PasswordBrokerRepository` propio con tenant, diferido a `REQ-AUTH`).

## 4. Datos de menores

Regla no negociable de `INV-008`: todo dato de un menor requiere base legal y consentimiento del tutor registrados. En fase 0 esto no aplica todavía porque no hay datos reales; el catálogo de bases legales por campo lo fija `REQ-PRIV-006` (ver `PRIVACY.md`).
