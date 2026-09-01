# SECURITY.md

> **Versión 0.2.1** · 2026-09-01
> Documento vivo: se actualiza en cada fase (`CLAUDE.md` §6), no solo al final. Sin datos reales todavía (`ADR-030`, entorno de desarrollo en WSL2).

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
| Autenticación de la SPA | Cookie de sesión `httpOnly`, `Secure`, `SameSite`, con CSRF. Prohibido JWT en `localStorage` bajo cualquier circunstancia. Política de contraseñas, bloqueo por intentos, recuperación, expiración por inactividad configurable | `ADR-025`, paso 1.2 |
| Sesiones activas y dispositivo nuevo | Panel de autoservicio con listado de sesiones, revocación individual/masiva y detección de login desde dispositivo no reconocido | `REQ-AUTH-005`, paso 1.2b |
| MFA (segundo factor) | TOTP con códigos de respaldo y correo electrónico como métodos alternativos, obligatoriedad configurable por rol (`MfaPolicy`) con período de gracia, excepciones temporales nominales acotadas a 90 días, restablecimiento por administrador | `REQ-AUTH-003`, `ADR-041`, pasos 1.3/1.3b |
| Login federado (Google) | OAuth2 con PKCE `S256`, `state` de un solo uso comparado en tiempo constante. Fusión automática de cuenta **solo** con `email_verified = true` (normalizado por lista blanca estricta, nunca `(bool)`); sin verificación, confirmación explícita desde la cuenta local. Ningún `access_token`/`refresh_token` de Google se persiste. El login federado pasa por las mismas comprobaciones que el local —bloqueo, estado de cuenta, `MfaPolicy` completo— sin saltarse ninguna. Ningún usuario se crea a partir de un login federado (alta automática diferida a `1.4b`, donde el directorio del propio centro sí lo justifica) | `REQ-AUTH-002`, `ADR-042`, paso 1.4 |
| Autorización por endpoint | Cada endpoint de negocio verifica autorización — por permiso, por identidad del portador de la sesión, o anónimo por diseño en los casos documentados — denegando por defecto (`INV-002`). El resolutor completo de permisos granulares (matriz recurso × acción × ámbito, roles personalizados) llega en el paso 1.5 | `INV-002`, paso 1.5 pendiente |
| Auditoría | Tabla `audit_logs` polimórfica, append-only, con `REVOKE UPDATE, DELETE` a nivel de motor para todos los roles de aplicación — inmutabilidad forzada, no por convención. Registro automático desde el ciclo de vida del ORM (`created`/`updated`/`deleted`/`restored`), implementado en el paso 0.9: ningún otro código de la aplicación serializa un atributo hacia esta tabla | `ADR-034` §3, `ADR-035`, paso 0.9 |
| Redacción de identificadores personales en auditoría | `changes` nunca contiene el valor de un secreto (contraseñas, *tokens*, semillas TOTP), de un atributo de categoría especial, ni de un identificador personal fuera de la lista de inclusión explícita del modelo (`Selective`, falla en cerrado). Solo se registra qué atributo cambió y si pasó de vacío a lleno. Ver `PRIVACY.md` §5 para el porqué | `ADR-035` |
| Datos de categoría especial (salud, NEAE, convivencia) | Se modelan en tablas separadas, cifradas, con permisos propios y auditoría de lectura. La política de redacción de `audit_logs` nunca registra el valor de un campo de categoría especial, solo qué atributo cambió | `CLAUDE.md` §8, `ADR-034` §3, `ADR-035` |
| Secretos | Fuera del código, en gestor de secretos (o variables de entorno no versionadas en desarrollo). Ninguna credencial en el histórico de git | `CLAUDE.md` §8 |
| Escaneo de dependencias | Trivy en cada PR (`dependency-scan.yml`), Renovate para actualizaciones | `CI/CD`, paso 0.6 |
| Revisión de seguridad | Subagente `security-reviewer` obligatorio antes de cada merge a `develop`, contra OWASP Top 10, aislamiento de tenant, permisos y datos de categoría especial. Clasifica hallazgos por severidad (`CLAUDE.md` §5) y bloquea el merge si algo es crítico | `CLAUDE.md` §6 |
| Consultas a base de datos | Parametrizadas siempre. Nunca concatenación de SQL | `CLAUDE.md` §8 |
| Ficheros subidos | Validación de tipo real, tamaño y almacenamiento fuera de la raíz web, con URL firmada de caducidad corta | `CLAUDE.md` §8 |

## 3. Qué falta todavía (no es una omisión, es el orden del plan)

- **SSO institucional** SAML 2.0/OIDC (`1.4b`) — el login federado con Google (`1.4`) ya está implementado (§2).
- **Autorización granular por endpoint**: el esquema de `roles`/`permissions` existe desde el paso 0.8 y cada endpoint de negocio implementado ya exige su permiso (`INV-002`), pero el resolutor completo (matriz recurso × acción × ámbito, roles personalizados, vista previa de permisos efectivos) llega en el paso 1.5.
- **Cabeceras de seguridad y CSP estricta**: se configuran en el primer despliegue real (`OPEN-11`), no en desarrollo.
- **Hallazgos de seguridad conocidos y ya registrados**, no bloqueantes hoy: [#6](https://github.com/pirexia/plataforma-educativa/issues/6) (`TenantContext::runAsPlatform()` sin auditoría, pendiente del sistema de permisos), [#7](https://github.com/pirexia/plataforma-educativa/issues/7) (ventana de caché en la suspensión de tenants), [#8](https://github.com/pirexia/plataforma-educativa/issues/8) (cookie de sesión host-only por defecto de Laravel, sin refuerzo activo), [#18](https://github.com/pirexia/plataforma-educativa/issues/18) (falta un `PasswordBrokerRepository` propio con tenant, reevaluar tras 1.2), [#81](https://github.com/pirexia/plataforma-educativa/issues/81) (`tenant_id`/RLS en `sessions` del framework, endurecimiento futuro).

## 4. Datos de menores

Regla no negociable de `INV-008`: todo dato de un menor requiere base legal y consentimiento del tutor registrados. En fase 0 esto no aplica todavía porque no hay datos reales; el catálogo de bases legales por campo lo fija `REQ-PRIV-006` (ver `PRIVACY.md`).
