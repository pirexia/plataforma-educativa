# SECURITY.md

> **Versión 0.2.3** · 2026-09-03
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
| Login federado (Google) | OAuth2 con PKCE `S256`, `state` de un solo uso comparado en tiempo constante. Fusión automática de cuenta **solo** con `email_verified = true` (normalizado por lista blanca estricta, nunca `(bool)`); sin verificación, confirmación explícita desde la cuenta local. Ningún `access_token`/`refresh_token` de Google se persiste. El login federado pasa por las mismas comprobaciones que el local —bloqueo, estado de cuenta, `MfaPolicy` completo— sin saltarse ninguna. Ningún usuario se crea a partir de un login federado | `REQ-AUTH-002`, `ADR-042`, paso 1.4 |
| SSO institucional (OIDC) | Catálogo de proveedores OIDC por tenant en autoservicio del propio centro (Azure AD/Entra ID, Google Workspace y cualquier emisor conforme). Descubrimiento del emisor con cinco guardas contra SSRF (esquema, rango de IP privada/reservada, `CURLOPT_RESOLVE` para cerrar el TOCTOU de DNS, límite de redirecciones, *timeout*), revalidadas en cada salto. Credencial de cliente por tenant cifrada en tabla propia con `APP_KEY`, nunca en claro, con ventana de rotación y aviso de caducidad a 30 días. Aprovisionamiento **solo por emparejamiento** con una `Person`/`User` ya existente en el censo — nunca crea cuentas nuevas (`ADR-043 §8.1`, riesgo de `INV-008` en un directorio institucional con alumnado). Mismas comprobaciones que el login local sin excepciones, `MfaPolicy` incluida. Restricción por dominio de correo configurable, con verificación explícita del *claim* `hd` de Google Workspace | `REQ-AUTH-004`, `ADR-043`, paso 1.4b |
| SSO institucional (SAML 2.0) | Perfil Web Browser SSO como *Service Provider*, con `SAML-Toolkits/php-saml` 4.x envuelto tras interfaz propia (`RNF-MANT-007`) — nunca se usa `OneLogin\Saml2\Auth`, solo su API de bajo nivel (`Settings`+`Response`) con `strict`, `wantAssertionsSigned`, `wantMessagesSigned` y `rejectUnsolicitedResponsesWithInResponseTo` fijados a `true` y verificados por reflexión sobre el objeto construido. Firma de la aserción verificada siempre, sin excepción por configuración. El proveedor —y por tanto el conjunto de certificados admisibles y el emisor esperado— se resuelve **desde la ruta del ACS, nunca desde el `Issuer` del mensaje**: la llave nunca se elige por el contenido de un mensaje aún sin verificar. `entityId` de SP y ACS URL son **por tenant**, derivados del *host* ya resuelto: tres barreras independientes (ruta, `Destination`, `Audience`) contra una aserción legítima de otro centro. Certificados del IdP con ventana de rotación (varios vigentes a la vez, extracción de vigencia del propio X.509, aviso de vencimiento) y retirada siempre manual. Mismas comprobaciones que el login local sin excepciones —bloqueo, estado de cuenta, `MfaPolicy` completo—, aprovisionamiento solo por emparejamiento, sin SSO iniciado por el IdP y sin *Single Logout*. Ver §2.1 para la excepción de CSRF del ACS | `REQ-AUTH-004`, `ADR-043`, paso 1.4c |
| Autorización por endpoint | Cada endpoint de negocio verifica autorización — por permiso, por identidad del portador de la sesión, por posesión de un artefacto de un solo uso emitido por el servidor (`state` de OIDC en sesión, o fila de correlación en base de datos para el ACS SAML), o anónimo por diseño en los casos documentados — denegando por defecto (`INV-002`). El resolutor completo de permisos granulares (matriz recurso × acción × ámbito, roles personalizados) llega en el paso 1.5 | `INV-002`, paso 1.5 pendiente |
| Auditoría | Tabla `audit_logs` polimórfica, append-only, con `REVOKE UPDATE, DELETE` a nivel de motor para todos los roles de aplicación — inmutabilidad forzada, no por convención. Registro automático desde el ciclo de vida del ORM (`created`/`updated`/`deleted`/`restored`), implementado en el paso 0.9: ningún otro código de la aplicación serializa un atributo hacia esta tabla | `ADR-034` §3, `ADR-035`, paso 0.9 |
| Redacción de identificadores personales en auditoría | `changes` nunca contiene el valor de un secreto (contraseñas, *tokens*, semillas TOTP), de un atributo de categoría especial, ni de un identificador personal fuera de la lista de inclusión explícita del modelo (`Selective`, falla en cerrado). Solo se registra qué atributo cambió y si pasó de vacío a lleno. Ver `PRIVACY.md` §5 para el porqué | `ADR-035` |
| Datos de categoría especial (salud, NEAE, convivencia) | Se modelan en tablas separadas, cifradas, con permisos propios y auditoría de lectura. La política de redacción de `audit_logs` nunca registra el valor de un campo de categoría especial, solo qué atributo cambió | `CLAUDE.md` §8, `ADR-034` §3, `ADR-035` |
| Secretos | Fuera del código, en gestor de secretos (o variables de entorno no versionadas en desarrollo). Ninguna credencial en el histórico de git | `CLAUDE.md` §8 |
| Escaneo de dependencias | Trivy en cada PR (`dependency-scan.yml`), Renovate para actualizaciones | `CI/CD`, paso 0.6 |
| Revisión de seguridad | Subagente `security-reviewer` obligatorio antes de cada merge a `develop`, contra OWASP Top 10, aislamiento de tenant, permisos y datos de categoría especial. Clasifica hallazgos por severidad (`CLAUDE.md` §5) y bloquea el merge si algo es crítico | `CLAUDE.md` §6 |
| Consultas a base de datos | Parametrizadas siempre. Nunca concatenación de SQL | `CLAUDE.md` §8 |
| Ficheros subidos | Validación de tipo real, tamaño y almacenamiento fuera de la raíz web, con URL firmada de caducidad corta | `CLAUDE.md` §8 |

### 2.1 · La excepción de CSRF del ACS SAML

**Es la primera y única excepción de CSRF de toda la aplicación** (`1.4c`, `REQ-AUTH-004`). El motivo es de protocolo, no de comodidad: un IdP SAML entrega la aserción al *Assertion Consumer Service* (ACS) mediante un `POST` HTTP entre sitios (*HTTP-POST binding*), y `SameSite=Lax` de la cookie de sesión no acompaña a ese `POST` — el navegador nunca envía la cookie, así que no hay token CSRF que verificar ni sesión de la que leerlo.

**Alcance de la excepción**: un grupo de rutas propio, con su propia pila de middleware declarada explícitamente, que cubre **únicamente** `POST /api/v1/auth/saml/{publicId}/acs`. No es una entrada en la lista global de exenciones de `validateCsrfTokens(except:)` — esa lista sigue vacía. Ninguna otra ruta de la aplicación está exenta.

**Por qué es segura sin CSRF**: la mitigación no es una excepción "confiada", es una sustitución equivalente. El ACS solo acepta una aserción cuyo `InResponseTo` case con una fila de `saml_auth_requests` que la propia aplicación emitió, que sigue viva (no caducada) y que no ha sido consumida — el consumo es atómico a nivel de SQL (`UPDATE ... WHERE consumed_at IS NULL` comprobando la fila afectada dentro de la misma transacción que valida la aserción, nunca lectura-luego-escritura), así que dos peticiones concurrentes con la misma aserción no pueden ganar las dos. Sin esa fila previa, la aserción se rechaza — es lo que excluye el SSO iniciado por el propio IdP (`ADR-043 §10.9` decisión 4): aceptarlo habría significado aceptar un `POST` sin CSRF y sin nada contra qué correlacionar, es decir, *login CSRF* real y sin mitigación.

**A esto se suma**: la firma de la aserción se verifica siempre (§2, fila "SSO institucional SAML 2.0"), y el proveedor —y por tanto el certificado admisible— se resuelve desde la propia ruta del ACS, nunca desde el contenido del mensaje.

## 3. Qué falta todavía (no es una omisión, es el orden del plan)

- **Autorización granular por endpoint**: el esquema de `roles`/`permissions` existe desde el paso 0.8 y cada endpoint de negocio implementado ya exige su permiso (`INV-002`), pero el resolutor completo (matriz recurso × acción × ámbito, roles personalizados, vista previa de permisos efectivos) llega en el paso 1.5.
- **Cabeceras de seguridad y CSP estricta**: se configuran en el primer despliegue real (`OPEN-11`), no en desarrollo.
- **Hallazgos de seguridad conocidos y ya registrados**, no bloqueantes hoy: [#6](https://github.com/pirexia/plataforma-educativa/issues/6) (`TenantContext::runAsPlatform()` sin auditoría, pendiente del sistema de permisos), [#7](https://github.com/pirexia/plataforma-educativa/issues/7) (ventana de caché en la suspensión de tenants), [#8](https://github.com/pirexia/plataforma-educativa/issues/8) (cookie de sesión host-only por defecto de Laravel, sin refuerzo activo), [#18](https://github.com/pirexia/plataforma-educativa/issues/18) (falta un `PasswordBrokerRepository` propio con tenant, reevaluar tras 1.2), [#81](https://github.com/pirexia/plataforma-educativa/issues/81) (`tenant_id`/RLS en `sessions` del framework, endurecimiento futuro).

## 4. Datos de menores

Regla no negociable de `INV-008`: todo dato de un menor requiere base legal y consentimiento del tutor registrados. En fase 0 esto no aplica todavía porque no hay datos reales; el catálogo de bases legales por campo lo fija `REQ-PRIV-006` (ver `PRIVACY.md`).
