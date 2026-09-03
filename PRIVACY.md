# PRIVACY.md

> **Versión 0.2.2** · 2026-09-02
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
| Auditoría sin copia en claro de datos protegidos | El registro de auditoría (`audit_logs`, implementado en el paso 0.9) nunca guarda el valor de un secreto, de un campo de categoría especial, ni de un identificador personal fuera de la lista de inclusión explícita del modelo — solo qué atributo cambió. Tampoco guarda el nombre del actor desnormalizado, para que la anonimización de una persona no deje un rastro legible en el histórico. Detalle en §5 | `ADR-034` §3, `ADR-035` |
| Datos de menores | Base legal y consentimiento del tutor registrados para todo dato de un menor, no asumidos | `INV-008` |
| Datos de prueba nunca reales | Bajo ningún concepto, ni una exportación del centro ni una copia de producción para depurar | `ADR-030` |

### 2.1 Inventario de cookies propias

| Cookie | Módulo / paso | Finalidad | Clasificación |
|--------|----------------|-----------|----------------|
| `pge_device` | `REQ-AUTH-005` (1.2b) | Reconocer el navegador de un usuario ya autenticado para avisarle de accesos desde un dispositivo no reconocido (detección de dispositivo nuevo). Opaca, sin dato personal alguno dentro, emitida **solo** tras una autenticación correcta, de solo el titular de la cuenta. | **Cookie técnica de seguridad, exenta de consentimiento** (`funcional.md §B.6.2`, decisión del usuario en `funcional.md §B.14` punto 2, `OPEN-AUTH-14`). No hay perfilado ni cesión a terceros. Vida 365 días (`AUTH_DEVICE_COOKIE_TTL_DAYS`), `httpOnly`, `Secure`, `SameSite=Lax`, *host-only*. |
| Cookie de sesión (Laravel) | `REQ-AUTH` (1.2) | Mantener autenticado al usuario entre peticiones tras el login (`ADR-025`) — identificador de sesión opaco, sin dato personal dentro; el servidor asocia la sesión al usuario en su propio almacén. | **Cookie técnica estrictamente necesaria, exenta de consentimiento** (`RGPD` art. 6.1.f / excepción de cookies técnicas — no hay finalidad distinta de prestar el servicio solicitado). No hay perfilado ni cesión a terceros. Vida ligada a `SESSION_LIFETIME` (expiración por inactividad configurable), `httpOnly`, `Secure`, `SameSite`, *host-only*. |
| `XSRF-TOKEN` | `REQ-AUTH` (1.2) | Token anti-CSRF legible por JavaScript, exigido por la SPA en cada petición mutante para probar que la petición se originó en el propio frontend | **Cookie técnica estrictamente necesaria, exenta de consentimiento** — mismo fundamento que la cookie de sesión, es su contrapartida de seguridad, no un tratamiento adicional. No legible entre orígenes distintos (`SameSite`), sin dato personal dentro. |

### 2.2 Datos recibidos de un proveedor de identidad externo (Google, `REQ-AUTH-002`, paso 1.4)

El *callback* de OAuth2 recibe de Google, además del `access_token`/`id_token` de la propia negociación, un conjunto de datos personales de la persona que inicia sesión. Qué entra y qué se descarta, decidido en `ADR-042` y `funcional.md §E.0.2`/`§E.4`:

| Dato que envía Google | ¿Se persiste? | Dónde / por qué |
|------------------------|----------------|------------------|
| `sub` (identificador estable del proveedor) | **Sí** | `user_identities.subject`. Es la identidad federada real (nunca el correo, `RN-AUTH-86`); permite correlacionar a la persona fuera de este producto, por eso no sale por ninguna API (`permisos.md §E.5`) |
| `email` | **Sí, solo en la fusión inicial** | `user_identities.email_at_link`, **enmascarado** en toda salida (`DestinationMasker`). No se usa para reconocer al usuario en accesos posteriores (eso es `sub`) |
| `email_verified` | **No se persiste el valor**, solo su efecto | Decide si la fusión automática procede (`RN-AUTH-87`); normalizado por lista blanca estricta en un único punto (`ADR-042 §4.4`) para que un `(bool) 'false'` no abra una vía de apropiación de cuenta |
| `given_name` / `family_name` | **No** | `RN-AUTH-88`: Google **nunca** sobrescribe datos del centro, ni al vincular ni en logins posteriores. El nombre que vale es el de la ficha de la persona en el centro. Partir `family_name` en `family_name_1`/`family_name_2` sin criterio no arbitrario es, además, el motivo por el que ni se intenta (`funcional.md §E.0.2` contradicción 2) |
| `picture` (URL del avatar) | **No, bajo ningún concepto** | Ni se descarga ni se guarda la URL. Servirla filtraría a Google la IP de quien la mire; guardarla sería tratar un dato personal nuevo sin base legal decidida (`REQ-PRIV-006`, `OPEN-13`) |
| `access_token` / `refresh_token` | **No** | Se usan en la misma petición servidor-a-servidor para leer los *claims* y se descartan de inmediato (`RN-AUTH-95`). Este producto no llama a ninguna API de Google en nombre de nadie; guardar un *refresh token* sería custodiar una llave a la cuenta personal de una persona, con su propia base legal y su propia superficie de fuga |

**Ningún usuario se crea a partir de este flujo** (`RN-AUTH-99`): sin cuenta local ya existente en el centro, no se trata ningún dato de los de arriba — se descartan en el acto. `REQ-AUTH-004`/`1.4b` (§2.3) tampoco crea usuarios: el directorio del propio centro contiene también alumnado, y un aprovisionamiento automático que confiara en la aserción crearía el registro de un menor sin base legal ni consentimiento del tutor (`INV-008`) — decisión del usuario, `ADR-043 §8.1`.

### 2.3 Datos recibidos de un proveedor de identidad institucional (OIDC, `REQ-AUTH-004`, paso 1.4b)

Cada centro cataloga su propio proveedor de identidad (Azure AD/Entra ID, Google Workspace u otro emisor conforme a OIDC). El *callback* recibe del `id_token` un conjunto de *claims* de la persona que inicia sesión. Qué entra y qué se descarta, decidido en `ADR-043` y `funcional.md §F.5`:

| *Claim* que envía el proveedor | ¿Se persiste? | Dónde / por qué |
|---------------------------------|----------------|------------------|
| `sub` (identificador estable del emisor) | **Sí** | `user_identities.subject`, junto a `identity_provider_id` — un mismo `sub` solo es único dentro de su emisor (`ADR-043 §3.6`), nunca el correo |
| `email` (o el *claim* configurado como origen del correo) | **Sí, solo para resolver el emparejamiento** | Se usa para localizar la `Person`/`User` ya existente en el censo con ese correo; no se persiste como dato nuevo del proveedor, es el mismo `contact_email` que ya tenía la persona en el centro |
| Resto de atributos (`given_name`, `family_name`, teléfono, etc.) | **No, bajo ningún concepto** | `RN-AUTH-88`/`OPEN-AUTH-38`: el mapeo de atributos de este paso resuelve identidad, no escribe sobre `people` — la persona ya tiene sus datos puestos por el centro, y escribir encima sería sobrescribirlos sin su acto. La lista blanca de campos que **podría** rellenar el día que exista esa mitad está documentada, sin materializar, en `funcional.md §F.5.3` |
| Fecha de nacimiento | **No se lee ni se pide** | Es justo el dato cuya ausencia impide saber si el directorio acaba de entregar la identidad de un menor — motivo central de que este paso no cree cuentas (`ADR-043 §4.1`) |
| Credencial de cliente del centro (`client_secret` o equivalente) | **Sí, cifrada** | `identity_provider_secrets.client_secret`, cifrada con `APP_KEY` a nivel de aplicación, nunca en claro por ningún endpoint. Es material de configuración del centro, no un dato personal de quien inicia sesión |

**Ningún usuario ni persona nueva se crea a partir de este flujo.** Un acceso sin cuenta ya existente en el centro con ese correo termina sin vincular y sin crear nada, con la misma respuesta genérica e indistinguible que el resto de casos "sin cuenta" (`funcional.md §F.4.5`).

### 2.4 Datos recibidos de un proveedor de identidad institucional (SAML 2.0, `REQ-AUTH-004`, paso 1.4c)

Mismo mecanismo que §2.3, protocolo distinto: cada centro cataloga su propio proveedor SAML (ADFS, Entra ID en modo SAML, Shibboleth u otro emisor conforme). La aserción que llega al ACS trae el `NameID` y, si se configuró, un atributo adicional de correo. Qué entra y qué se descarta, decidido en `ADR-043 §10` y `funcional.md §G.5`:

| Elemento que envía el proveedor | ¿Se persiste? | Dónde / por qué |
|----------------------------------|----------------|------------------|
| `NameID` (identificador estable del sujeto) | **Sí** | `user_identities.subject`, junto a `identity_provider_id` — mismo papel que `sub` en OIDC (§2.3). **No es configurable**: es el identificador del sujeto en el estándar, y dejarlo elegible sería ofrecer al administrador del centro identificar por un atributo cualquiera (`ADR-043 §4.4`) |
| Atributo de correo (`email_attribute`, o el propio `NameID` si su formato es `emailAddress`) | **Sí, solo para resolver el emparejamiento** | Igual que `email` en OIDC: localiza la `Person`/`User` ya existente en el censo; no se persiste como dato nuevo del proveedor |
| Resto de atributos del directorio (nombre, apellidos, teléfono, etc.) | **No, bajo ningún concepto** | Mismo argumento que §2.3: el mapeo de este paso resuelve identidad, no escribe sobre `people` (`RN-AUTH-109`, `OPEN-AUTH-38` salida A) |
| Fecha de nacimiento | **No se lee ni se pide** | Mismo motivo que §2.3: es justo el dato cuya ausencia impide saber si el directorio acaba de entregar la identidad de un menor |
| **La aserción XML completa, o cualquier fragmento suyo** | **No, bajo ningún concepto** | A diferencia de OIDC (donde el `id_token` se descarta tras leer los *claims*), aquí se declara explícitamente porque la aserción viaja por el navegador en un `POST` de un tercero: de ella solo sobreviven su `ID` y su fecha de expiración (`NotOnOrAfter`) en `saml_consumed_assertions`, con el único propósito de impedir su repetición (`RN-AUTH-95` ampliado, `CA-AUTH-363`) — ninguno de los dos identifica a una persona |
| Certificado de firma del proveedor | **Sí, en claro** | `identity_provider_certificates.certificate` — es una **clave pública** del centro, material de configuración institucional, no un dato personal de quien inicia sesión (`RN-AUTH-127`) |
| Clave privada de firma de nuestra plataforma (si `sign_authn_requests` está activo) | **No vive en base de datos** | Fichero de plataforma fuera del repositorio y fuera de la copia de la base de datos (`SYSADMIN.md`, `operacion.md §G.2.3`). No es un dato de ninguna persona ni de ningún centro: es un secreto operativo de la plataforma |

**Ningún usuario ni persona nueva se crea a partir de este flujo**, con la misma garantía de esquema que en OIDC (`ADR-043 §10.9`, el `CHECK` de `provisioning_mode` lo impide independientemente del protocolo). Un acceso sin cuenta ya existente en el centro con ese identificador termina sin vincular y sin crear nada, con la misma respuesta genérica e indistinguible que el resto de casos "sin cuenta" (`funcional.md §G.4.5`).

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
