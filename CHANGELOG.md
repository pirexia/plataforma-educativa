# CHANGELOG

Historial de la documentación del proyecto. Cuando exista código, este fichero recogerá también las versiones de la aplicación.

Formato: versionado semántico por documento. Mayor = cambio que invalida decisiones previas. Menor = contenido nuevo. Parche = correcciones.

---

## 2026-09-02/04 · Cierre de 1.4c (`REQ-AUTH-004`, parte 2/2: SSO institucional SAML 2.0)

### Nuevo: SAML 2.0 como segundo protocolo del catálogo `identity_providers`
`architect` reevaluó en vivo, al abrir el paso, la comparación de bibliotecas SAML PHP que `ADR-043 §7.3` había dejado sin recomendación firme: dos de sus cuatro observaciones resultaron equivocadas (`litesaml/lightsaml` tuvo un salto de autenticación real por *XML Signature Wrapping* en 5.0.0 sin aviso publicado en Packagist ni GHSA; `simplesamlphp/saml2` v6 abandonó `xmlseclibs` por una biblioteca propia con escrutinio externo casi nulo). Recomendación resultante y decisión del usuario: **`SAML-Toolkits/php-saml` 4.x**, envuelta tras interfaz propia (`RNF-MANT-007`), nunca `OneLogin\Saml2\Auth` directamente.

Ocho decisiones más del usuario (2026-09-02, `ADR-043 §10.9`, todas siguiendo la recomendación): catálogo con discriminador `protocol` + tabla hija `saml_identity_provider_settings` (1:1, sin mover las columnas OIDC de 1.4b); excepción de CSRF acotada a un grupo de rutas propio solo para el ACS, nunca una lista global; **sin SSO iniciado por el IdP** (precondición de seguridad de esa misma excepción: sin petición previa que correlacionar, sería *login CSRF* sin mitigación); objeto de valor propio para la identidad SAML (no reutiliza `ExternalIdentity`, que depende de `email_verified`, inexistente en SAML); clave de firma del SP única de plataforma, por fichero montado y variable de entorno, nunca en base de datos; MFA propio nunca exento aunque el IdP declare su propio segundo factor; sin intermediario externo (Keycloak/Authentik). Siete preguntas más de `spec-writer` al detallar la especificación (`OPEN-AUTH-42`-`48`), todas resueltas por el usuario siguiendo la recomendación.

Correlación de la petición SAML en servidor (`saml_auth_requests`, consumo atómico de un solo uso), gestión y rotación de certificados de firma del IdP con retirada manual, obtención de metadatos por URL (reutilizando las cinco guardas SSRF de 1.4b) o XML pegado. Aprovisionamiento solo por emparejamiento, igual que OIDC — ya impuesto por el `CHECK` de `identity_providers.provisioning_mode` desde 1.4b, no una decisión nueva de este paso.

### Corregido
- **Media** (revisión independiente `db-reviewer`): `identity_provider_certificates_tenant_provider_fingerprint_unique` medía 65 caracteres — PostgreSQL lo habría truncado en silencio al límite de 63. Renombrado a `...tenant_provider_fp_unique`.
- **Media** (revisión independiente `db-reviewer`): el índice de purga de `saml_auth_requests` solo cubría la mitad de la condición real de la consulta (filas caducadas sin consumir); la rama de filas ya consumidas —es decir, todo login SSO SAML exitoso— se quedaba sin índice de apoyo hasta purgarse, mismo patrón que motivó los issues #118/#119 de `1.3b`. Añadido el índice que faltaba.
- **Alta** (revisión independiente `security-reviewer` y `doc-reviewer`, coincidente): el ACS —la única ruta sin CSRF de la aplicación— no tenía ningún test ejecutándolo de verdad, pese a que el código citaba varios criterios de aceptación como si estuvieran verificados. Issue [#152](https://github.com/pirexia/plataforma-educativa/issues/152).
- **Alta** (revisión independiente `doc-reviewer`): `SECURITY.md` remitía a una `§2.1` inexistente para la excepción de CSRF del ACS. Añadida.
- **Alta** (revisión independiente `doc-reviewer`): `apps/api/openapi.yaml` no registraba los cinco endpoints nuevos del paso (los esquemas existían en `openapi/paths/sso.yaml` pero no estaban enlazados). Corregido.
- **Media** (revisión independiente `doc-reviewer`): `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` seguía describiendo `REQ-AUTH-004` con aprovisionamiento por creación automática ("*Just-in-Time provisioning*"), contradiciendo la decisión de solo-emparejamiento tomada en `1.4b`.
- **Alta** (escribiendo los tests que pedía el hallazgo anterior, issue [#155](https://github.com/pirexia/plataforma-educativa/issues/155)): el IdP SAML simulado (`FakeSamlIdentityProviderController`) nunca firmaba el `<samlp:Response>`, solo la `Assertion` interna — ningún login SAML simulado podía completarse con éxito. Causa raíz añadida al investigar: el material de firma se cacheaba con `Cache::rememberForever()` bajo `CACHE_STORE=redis` forzado solo en `phpunit.xml`, pero el flujo cruza el proceso de Pest y el `artisan serve` persistente del contenedor, cada uno con su propia clave — mismo patrón de hueco de entorno que ya costó tres capas de fallo en CI durante `1.4b`. Corregido firmando también el `Response` y pasando el material de firma a un fichero compartido.
- **Alta** ([#156](https://github.com/pirexia/plataforma-educativa/issues/156)): `PATCH /identity-providers/{id}` nunca validaba ni aplicaba ningún campo exclusivo de SAML (`sign_authn_requests` incluido) — `UpdateIdentityProviderRequest::targetProtocol()` comparaba `is_string()` contra un valor que Eloquent ya devolvía convertido al enum `Protocol`, siempre falso. Corregido.
- **Media** ([#157](https://github.com/pirexia/plataforma-educativa/issues/157)): `datos.md §G.5` afirmaba que la huella del certificado del IdP sí se registraba en `audit_logs`, citando en la misma frase dos secciones de `ADR-043` que dicen justo lo contrario. Era `datos.md` el equivocado, no `funcional.md`/`RN-AUTH-127`. Corregidos el modelo (`fingerprint_sha256` a `$auditSecretAttributes`) y el texto de `datos.md`.
- **Media** (hallazgo de test, sin issue propio — infraestructura, no producción): `CA-AUTH-354` (vínculo de MFA pendiente) fallaba por `Illuminate\Session\DatabaseSessionHandler::$exists`, una bandera de instancia reutilizada entre peticiones de sesiones distintas dentro del mismo test, que convertía en silencio el `INSERT` de la sesión del ACS en un `UPDATE` de cero filas. Corregido con `resetSessionState()` en el *helper* de test, sin tocar producción.
- **Media** (segunda pasada `doc-reviewer`): cuatro de los cinco documentos de la Parte G (`datos.md`, `permisos.md`, `operacion.md`, `api.md`) seguían con la cabecera "especificada y pendiente de aprobación" desde el commit inicial de la especificación, pese a que `funcional.md` ya decía `APROBADA`. Alineadas las cuatro.
- **Media** (segunda pasada `doc-reviewer`): la nota de `memory.md` § "Trabajo en curso" afirmaba que los issues #152/#155/#156/#157 seguían sin cerrar cuando ya lo estaban. Corregida.

### Diferido a propósito (issues abiertos)
[#153](https://github.com/pirexia/plataforma-educativa/issues/153) (Baja): nombre de FK de `1.4b` (`identity_provider_secrets`) también truncado a 63 bytes por PostgreSQL, hallazgo colateral de la revisión de `1.4c`, sin código de aplicación que lo referencie hoy · [#154](https://github.com/pirexia/plataforma-educativa/issues/154) (Baja): aviso de expiración de certificados SAML en el manual de administrador más vago que el resto del propio documento · [#158](https://github.com/pirexia/plataforma-educativa/issues/158) (Media, segunda pasada `security-reviewer`): la guarda anti-SSRF de `SsrfSafeFetcher` sí se repite en cada salto de redirección (verificado contra el código real, sin vulnerabilidad), pero `CA-AUTH-320` se queda sin test que lo ejerza, mismo hueco de cobertura ya existente desde `1.4b` para su hermano `CA-AUTH-263` · [#159](https://github.com/pirexia/plataforma-educativa/issues/159) (Baja): 5 alertas de Dependabot nuevas (`fast-uri`/`qs`), transitivas de `shadcn-vue` (CLI, no llegan al bundle de producción).

### Revisión independiente
Dos pasadas de cada uno. **Primera pasada**: `security-reviewer` 1 Alta bloqueante (tests del ACS ausentes, issue #152, coincidente con `doc-reviewer`); `db-reviewer` 2 Media; `doc-reviewer` 3 Alta bloqueantes y 2 Media — la coherencia código↔documentación del propio módulo `REQ-AUTH` no tuvo hallazgos, todo lo bloqueante fue vigencia de documentos raíz y OpenAPI. Al escribir los tests que cerraban el hallazgo de #152, `test-writer` encontró y dejó documentados tres bugs reales más (#155, #156, #157, detalle arriba), corregidos por la sesión orquestadora. **Segunda pasada**, sobre el código ya corregido: `db-reviewer` sin hallazgos nuevos; `doc-reviewer` 2 Media (cabeceras y `memory.md`, detalle arriba); `security-reviewer` sin hallazgos Crítico/Alta, 1 Media no bloqueante (#158).

Backend: **506 tests Pest en verde** (`php -d memory_limit=512M ./vendor/bin/pest`, issue #106), incluidos los 56 que referencian `CA-AUTH-311` a `366` contra el ACS real. Frontend: dos pantallas ampliadas (`/administracion/sso`, `/administracion/sso/{public_id}`), ninguna nueva — **118/118 tests Vitest en verde**, `pint`/Larastan/`eslint`/`lint:i18n`/`vue-tsc` limpios. Mezclado a `develop` vía PR [#160](https://github.com/pirexia/plataforma-educativa/pull/160). Detalle completo en `docs/historial/1.4c-sso-institucional-saml.md`.

---

## 2026-09-01/02 · Cierre de 1.4b (`REQ-AUTH-004`, parte 1/2: SSO institucional OIDC y aprovisionamiento por emparejamiento)

### Nuevo: catálogo de proveedores OIDC por tenant, login institucional, aprovisionamiento por emparejamiento
`ADR-043` divide `REQ-AUTH-004` en dos pasos tras la evaluación previa de `architect`: SAML rompe a la vez el mecanismo de sesión del *callback* (`SameSite=Lax` no acompaña un `POST` entre sitios), el envoltorio de la dependencia (`ExternalIdentity` está construido sobre `email_verified`, que SAML no tiene), el perfil de riesgo (verificado contra Packagist: patrón recurrente de fallos de validación de firma en las bibliotecas PHP candidatas) y el ciclo de vida del certificado — ninguno de los cuales afecta a OIDC. `1.4b` construye el modelo completo (catálogo, aprovisionamiento, auditoría); `1.4c` (posterior) solo añade el protocolo SAML sobre él.

Catálogo `identity_providers` por tenant, en autoservicio del propio administrador del centro (Azure AD/Entra ID, Google Workspace con verificación del *claim* `hd`, o cualquier emisor conforme a OIDC) — sin ningún paso manual de operador, a diferencia de Google (1.4). Validación del documento de descubrimiento con cinco guardas contra SSRF (esquema, rango de IP privada/reservada, `CURLOPT_RESOLVE` para cerrar el TOCTOU de DNS, límite de redirecciones, *timeout*), revalidadas en cada salto. Credencial de cliente por tenant cifrada en tabla propia (`identity_provider_secrets`) con la clave de aplicación, con ventana de rotación sin corte de servicio y aviso de caducidad a 30 días. `user_identities` re-tecleada por proveedor concreto en vez de por protocolo (`ADR-043 §3.6`): la clave de `1.4` asumía que `provider` identificaba al emisor, falso en cuanto un centro puede tener más de un IdP institucional a la vez.

Tres decisiones del usuario (2026-09-01), las tres siguiendo la recomendación de la especificación: **aprovisionamiento solo por emparejamiento** con una `Person`/`User` ya existente en el censo, nunca crea cuentas nuevas — un directorio institucional contiene también alumnado, y crear automáticamente sin conocer la fecha de nacimiento incumpliría `INV-008` (`ADR-043 §4.1`, `OPEN-AUTH-38`); **credencial cifrada en tabla propia**, no en gestor externo (coherente con `ADR-037 §7`); **configuración del IdP en autoservicio del centro**, no del operador de la plataforma. Consecuencia aceptada y documentada: la tercera línea del requisito ("mapeo automático de atributos... a campos de usuario") queda cubierta solo en su mitad de identidad — el mapeo resuelve quién es la persona, no escribe sobre `people`, porque escribir encima de datos ya puestos por el centro es lo que `RN-AUTH-88` prohíbe desde 1.4.

Mismas comprobaciones que el login local, sin excepciones (`RN-AUTH-111`): bloqueo, estado de cuenta (`pendiente` no entra por SSO, decisión del usuario), `MfaPolicy` completo. 9 endpoints nuevos y 2 modificados, 4 permisos nuevos (`proveedor_identidad.*`, solo `administrador_centro`), 2 tareas programadas (refresco de descubrimiento, aviso de caducidad de credencial). Emisor OIDC simulado (`FakeOidcIssuerController`, dos barreras contra producción) para desarrollo y tests. Especificación aprobada en `docs/modulos/REQ-AUTH/*.md` Parte F.

Backend: 451 tests Pest en verde (`php -d memory_limit=512M ./vendor/bin/pest`, issue #106), incluidos 47 que referencian `CA-AUTH-260` a `310` contra el emisor simulado por HTTP real. Frontend: 5 pantallas (botones en `/entrar`, `/entrar/sso`, catálogo y alta/edición en `/administracion/sso`, bloque ampliado en `/cuenta/seguridad`), 106 tests Vitest en verde, `eslint`/`lint:i18n`/`vue-tsc` limpios. Verificado en navegador real (Playwright MCP) con el emisor simulado.

### Corregido
- **Alta** (revisión independiente `db-reviewer`): la migración de re-tecleado de `user_identities` (`2026_09_01_100500`) creaba los cinco índices y validaba la `FK`/los `CHECK` dentro de la transacción por defecto, bloqueando lecturas y escrituras sobre una tabla viva desde `1.4` durante todo el recorrido. Reescrita con `$withinTransaction = false` y el patrón `CREATE INDEX CONCURRENTLY`/`NOT VALID` + `VALIDATE CONSTRAINT` incondicional, igual que el precedente ya establecido en `2026_08_31_100100_add_purge_indexes_to_mfa_tables.php` (issues #118/#119).
- **Media** (revisión independiente `db-reviewer`): el nombre nuevo de dos índices insertaba un sufijo `_null` en medio del nombre antiguo, rompiendo la subcadena que `GoogleOAuthCallbackService` (código ya desplegado desde 1.4, sin cambios propios de este paso) usa para distinguir el motivo de un rechazo — durante la ventana de un despliegue continuo, una instancia antigua habría mostrado el mensaje equivocado. Corregido moviendo el sufijo al final del nombre, sin tocar código de aplicación salvo un comentario explicativo. El comentario de cabecera de la propia migración, que además contradecía a su propio `up()` sobre cuándo se retiraban los índices antiguos, también corregido.
- **Baja** (revisión independiente `db-reviewer`, defensa en profundidad): ningún `CHECK` impedía `link_method = 'fusion_automatica'` con `identity_provider_id` informado — solo lo evitaba el código de aplicación, contradiciendo la afirmación de `datos.md §F.8` de que nada vive solo en la aplicación. Añadido `user_identities_fusion_no_provider_check`, simétrico al ya existente para `google`.
- **Media** (issue [#147](https://github.com/pirexia/plataforma-educativa/issues/147)): las cinco pantallas nuevas o ampliadas (~1700 líneas) no tenían ningún test Vitest propio — mismo patrón débil ya documentado en 1.2/1.2b/1.3 (issues #116/#120), esta vez con mucho más código sin cubrir, y al menos `CA-AUTH-269` ("la pantalla no pinta ningún botón") solo verificado a nivel de contrato de API, no de pantalla. 60 tests Vitest nuevos, incluida paridad de traducciones en los cuatro idiomas.
- **Media** (issue [#148](https://github.com/pirexia/plataforma-educativa/issues/148), hallazgo al escribir los tests del punto anterior): seis de los trece mensajes del *callback* institucional reutilizaban literalmente las claves de `GoogleCallbackResultView.vue` y mencionaban "Google" explícitamente, aunque el usuario acababa de entrar por el proveedor de su centro. Nuevo juego neutro `auth.ssoCallback.*` en los cuatro idiomas, decisión del usuario: texto genérico, sin interpolar el nombre del proveedor.
- **Alta** (revisión independiente `doc-reviewer`): `CHANGELOG.md` sin la entrada de cierre de `1.4` (esta misma entrada, en el ciclo anterior) seguía describiendo su PR como "abierto, sin mezclar todavía" pese a estar mezclado desde `b19f29b` — recurrencia del patrón que motivó `CLAUDE.md §6.7`; corregida junto con la tabla de versiones de `README.md` (`docs/REQUISITOS-...md` en `3.1.2` cuando esta misma rama la subió a `3.1.3`) y el recuento de ADR (`32` cuando ya son `43`).
- **Alta** (revisión independiente `doc-reviewer`): `SECURITY.md` seguía listando SSO/OIDC como pendiente; `PRIVACY.md` sin el flujo de datos del IdP institucional (nuevo §2.3) y con una frase desactualizada sobre el aprovisionamiento automático; `docs/manual-usuario/admin.md` sin ninguna mención del autoservicio nuevo (`/administracion/sso`) pese a que el manual ya existe y cubre funciones de configuración de centro comparables.
- **Media** (revisión independiente `doc-reviewer`): `operacion.md §F.2.1` no listaba `AUTH_SSO_TOKEN_TIMEOUT_SECONDS`, pese a ser una variable real ya documentada en `SYSADMIN.md`; la entrada `OPEN-13` del índice de decisiones abiertas no mencionaba que `1.4b` también queda bloqueada por ella (fotografía del mapeo de atributos).

### Diferido a propósito (issues abiertos)
[#145](https://github.com/pirexia/plataforma-educativa/issues/145) (Baja): `people.locale` sin `CHECK` y con `DEFAULT` fuera del conjunto admitido — columna de `REQ-CORE`, detectada al escribir la especificación de este paso, no la agrava (`1.4b` no escribe sobre `people`) · [#146](https://github.com/pirexia/plataforma-educativa/issues/146) (Baja): `php artisan serve` de un solo hilo interbloquea el alta desde el navegador de un proveedor auto-referenciado al propio servidor de desarrollo — no afecta a producción (FrankenPHP) ni al uso real.

### Revisión independiente
`security-reviewer`: sin hallazgos Crítico/Alto, los doce puntos de atención verificados contra código real (SSRF, cifrado de credencial, aislamiento de tenant, sin creación automática, resolución de tenant desde sesión, restricción por dominio, MFA sin excepción, etc.). `db-reviewer`: 1 hallazgo Alta bloqueante y 2 Media, todos corregidos antes de mezclar (detalle arriba). `doc-reviewer`: 5 hallazgos Alta bloqueantes y 2 Media, todos corregidos antes de mezclar — la coherencia código↔documentación del propio módulo `REQ-AUTH` no tuvo ningún hallazgo; todo lo bloqueante fue vigencia de documentos raíz y manual de usuario.

---

## 2026-09-01 · Cierre de 1.4 (`REQ-AUTH-002`: login con Google y fusión de cuentas)

### Nuevo: login federado con Google, fusión, vinculación y desvinculación
OAuth2 con PKCE `S256`, seis endpoints (descubrimiento, arranque, *callback*, `GET /auth/mfa-challenges` sobre un recurso de 1.3, autoservicio de vínculos). Fusión automática de cuenta **solo** con `email_verified = true` normalizado por lista blanca estricta en un único punto (`SocialiteGoogleIdentityProvider`, `ADR-042 §4.4`); sin verificación, salida indistinguible de "no hay cuenta" (`RN-AUTH-87`, evita el oráculo de enumeración). Ningún usuario se crea a partir de un login federado — interpretación restrictiva decidida el 2026-08-31 (`RN-AUTH-99`, `OPEN-AUTH-31`), el aprovisionamiento automático queda para `1.4b`. El login federado pasa por las mismas comprobaciones que el local y en el mismo orden (bloqueo, estado de cuenta, `MfaPolicy` completo) sin saltarse ninguna. Ningún *token* de Google se persiste. Tres pantallas: botón "Continuar/Vincular con Google" (solo visible si el proveedor está configurado, `RN-AUTH-98`), `/entrar/google` (destino del *callback*, reutiliza el paso 2 de MFA ya existente), bloque "Cuentas vinculadas" en `/cuenta/seguridad`. `ADR-042` aprueba `laravel/socialite ^5.30` tras interfaz propia (`IdentityProvider`), único fichero autorizado a importar `Laravel\Socialite\*`. Proveedor simulado (`AUTH_OAUTH_DRIVER=fake`, dos barreras contra producción) para desarrollo y tests, necesario porque `0.10b` (dominio público) sigue pendiente y Google no admite *callbacks* sin TLS sobre un dominio registrable. Especificación aprobada en `docs/modulos/REQ-AUTH/*.md` Parte E. Los 49 tests Pest propios de `REQ-AUTH-002` (`GoogleLoginTest`, `IdentityLinkingTest`, `SocialiteGoogleIdentityProviderTest`, `AuthTranslationsParityTest`) en verde de forma repetida y estable; `pint`/`phpstan` limpios. Una ejecución completa de toda la suite del backend en esta sesión pasó limpia (406 tests); ejecuciones posteriores de la suite completa, en la misma sesión interactiva ya muy prolongada, resultaron inestables (fallos dispersos en ficheros sin relación con este paso, o el proceso terminado antes de completar) — no reproducible en los tests de este paso ejecutados solos, y compatible con presión de recursos de la propia sesión (contenedores recreados, decenas de conexiones `psql` manuales) más que con una regresión de código. Queda para que la ejecución de CI —entorno limpio en cada corrida— lo confirme antes de mezclar. Frontend (`eslint`/`lint:i18n`/`vue-tsc`+`build`/`vitest`, 39 tests) en verde. Verificado en navegador real (Playwright MCP) con el proveedor simulado: descubrimiento, fusión, vinculación con correo distinto, desvinculación (contraseña incorrecta y correcta), segundo factor, cancelado, sin cuenta — todo confirmado contra base de datos real, no solo la pantalla. Rama `feature/REQ-AUTH-002-google-login-fusion-cuentas`, PR [#143](https://github.com/pirexia/plataforma-educativa/pull/143) (*squash*, mezclado a `develop` en `b19f29b`).

### Corregido
- **Alta** (revisión independiente `doc-reviewer`): `RN-AUTH-99` —la regla que blinda la interpretación restrictiva de `OPEN-AUTH-31`— no tenía ningún test que la citara, y el caso central (`email_verified = true` **sin** ningún usuario local con ese correo) no estaba cubierto: el test más parecido (`CA-AUTH-207`) usa un correo con cuenta ya existente, así que prueba fusión, no ausencia de creación. Test nuevo que verifica `User::count()`/`Person::count()` sin cambios y que la sesión del *callback* sigue sin autenticar.
- **Alta** (revisión independiente `doc-reviewer`): `CHANGELOG.md` sin esta misma entrada — corregida la afirmación inicial (incorrecta, no contrastada contra `git log`) de que existía una convención del repositorio de diferirla a un commit posterior al *merge*; el patrón real, verificado, es incluirla en el propio cierre.
- **Media** (revisión independiente `doc-reviewer`, varios hallazgos): `label_key` en `GET /auth/identity-providers` documentaba un campo sin ningún consumidor real (retirado de backend, `oauth.yaml`, `api.md` y del tipo `IdentityProvider` del frontend, con una línea explicando por qué); `CA-AUTH-233`/`CA-AUTH-234` verificados a mano por el revisor sin regresión automatizada (añadidos: paridad de traducciones en los cuatro idiomas de `lang/*/auth.php`, y comprobación de que el logotipo de Google no carga ningún recurso externo); cinco cabeceras de la Parte E seguían diciendo "no implementada: es especificación previa" pese al cierre real (mismo criterio ya aplicado a las Partes B/C/D, issues #126/#127); tres referencias a que `ADR-042` estaba "en redacción" cuando ya está `ACEPTADA`; `SYSADMIN.md` documentaba solo 2 de las 3 guardas de arranque de `OAuthEnvironmentGuard` (faltaba la de HTTPS); `README.md`/`SECURITY.md`/`PRIVACY.md` sin reconciliar tras el cierre (estado del proyecto, tabla de versiones, "Google" todavía en "qué falta", flujo de datos de Google sin catalogar en el RAT).
- **Proceso**: `_ide_helper_models.php` conservaba una entrada obsoleta (`last_used_at` en vez de `last_login_at`, arrastrada de un renombrado de columna anterior a este cierre) que rompía `composer analyse` en cuanto la base de datos de desarrollo se reprovisionaba con el esquema correcto — corregida a mano, sin regenerar el fichero completo (la regeneración automática reformatea decenas de modelos no relacionados y corrompe *docblocks* existentes, comprobado y descartado). `MfaChallengeStep.vue` (extraído en un corte de cuota anterior) llamaba a una clave de traducción (`auth.mfaChallenge.loading`) que no existía en ningún idioma — activada por primera vez porque `/entrar/google` es el primer sitio que recupera un desafío de MFA sin datos iniciales; añadida en los cuatro idiomas.

### Diferido a propósito (issues abiertos)
[#141](https://github.com/pirexia/plataforma-educativa/issues/141) (Media): el `302` del *callback*, relativo y correcto para la topología de un solo origen de producción/*staging* (`ADR-028`), aterriza en el puerto de la API y no el de la SPA en el entorno de desarrollo de orígenes separados (`ADR-030`/issue #71) — verificado que el backend resuelve todo correctamente (base de datos), es un síntoma exclusivo de navegación manual en desarrollo. Dos propuestas de solución sin decidir cuál, mismo peso que el issue #71 original · [#142](https://github.com/pirexia/plataforma-educativa/issues/142) (Media, hallazgo de `db-reviewer`, sin relación con lo anterior): `RecordsAuditTrail` no excluye `last_login_at`/`last_used_at` de ninguno de los dos modelos que lo tienen, contradiciendo lo que ambos documentan.

### Revisión independiente
`security-reviewer` sin hallazgos. `db-reviewer` sin hallazgos bloqueantes (1 Media, #142, sin relación con este paso). `doc-reviewer`: 2 hallazgos Alta y varios Media, todos corregidos en la misma sesión antes de mezclar.

---

## 2026-08-31 · `chore/vigencia-documentacion-raiz`

### Nuevo: corrección de fondo para que la documentación no vuelva a quedarse desactualizada
El checklist de `doc-reviewer` solo cubría documentación de módulo, nunca documentos raíz (`SECURITY.md`, `README.md`, `PRIVACY.md`, `docs/REQUISITOS-...md`) ni el estado (`PROPUESTA`/`pendiente de aprobación`) de ADR y partes de módulo ya cerradas — llevaban desde 0.9/0.13 y desde cada cierre de fase respectivamente sin actualizarse. `.claude/agents/doc-reviewer.md` gana los puntos 9 y 10; `CLAUDE.md` §6 gana la regla 7 (v2.2.1→2.3.0).

### Corregido
- **Media** (issues [#111](https://github.com/pirexia/plataforma-educativa/issues/111)-[#114](https://github.com/pirexia/plataforma-educativa/issues/114)): `SECURITY.md`/`README.md`/`PRIVACY.md`/`docs/REQUISITOS-...md` describían un producto sin auth/MFA/permisos, con tabla de versiones desincronizada de 5 documentos, sin la fila `3.1.1` de su propio historial, y sin la cookie de sesión/`XSRF-TOKEN` en el RAT.
- **Media** ([#125](https://github.com/pirexia/plataforma-educativa/issues/125)): `CHANGELOG.md` sin las entradas de 1.2b y 1.3b.
- **Alta** ([#129](https://github.com/pirexia/plataforma-educativa/issues/129)): 11 de los 14 ADR en fichero propio (`028`-`038`) seguían `Estado: PROPUESTA` pese a estar implementados y ratificados de facto, incluido `ADR-033` (concreta la invariante crítica `INV-001`).
- **Media** ([#126](https://github.com/pirexia/plataforma-educativa/issues/126)/[#127](https://github.com/pirexia/plataforma-educativa/issues/127)): cabeceras de las Partes B/C/D de los cinco ficheros de `docs/modulos/REQ-AUTH/` seguían `pendiente de aprobación` pese a 1.2b/1.3/1.3b cerrados; `docs/modulos/REQ-CORE/funcional.md`/`datos.md` daban por pendiente el middleware `EnsureModuleEnabled` (ya implementado) y la aprobación de 1.1 (cerrado hace varias fases).
- **Alta** ([#128](https://github.com/pirexia/plataforma-educativa/issues/128), documentación corregida — infraestructura pendiente de decisión): ningún *worker* de colas (Horizon/`queue:work`) desplegado pese a 32 clases `ShouldQueue` reales y `QUEUE_CONNECTION=database` por defecto. `SYSADMIN.md`/`RUNBOOK.md` corregidos para reflejarlo con precisión; el despliegue del *worker* en sí queda para una decisión aparte (afecta a `ADR-028`/`ADR-037`).
- 13 issues cerrados por hallarse ya resueltos en código, dejados abiertos por descuido tras auditar la validez de los 49 issues abiertos: [#18](https://github.com/pirexia/plataforma-educativa/issues/18), [#36](https://github.com/pirexia/plataforma-educativa/issues/36), [#39](https://github.com/pirexia/plataforma-educativa/issues/39), [#49](https://github.com/pirexia/plataforma-educativa/issues/49), [#50](https://github.com/pirexia/plataforma-educativa/issues/50), [#52](https://github.com/pirexia/plataforma-educativa/issues/52), [#58](https://github.com/pirexia/plataforma-educativa/issues/58), [#66](https://github.com/pirexia/plataforma-educativa/issues/66), [#67](https://github.com/pirexia/plataforma-educativa/issues/67), [#70](https://github.com/pirexia/plataforma-educativa/issues/70), [#75](https://github.com/pirexia/plataforma-educativa/issues/75), [#95](https://github.com/pirexia/plataforma-educativa/issues/95), [#110](https://github.com/pirexia/plataforma-educativa/issues/110). [#62](https://github.com/pirexia/plataforma-educativa/issues/62) revisado y mantenido abierto: solo uno de sus cuatro puntos "Pendiente" está hecho.
- **Baja**: `ARCHITECTURE.md`/`CLAUDE.md` decían "52 módulos", el recuento canónico es 53.

### Diferido a propósito (issue abierto)
[#128](https://github.com/pirexia/plataforma-educativa/issues/128) (Alta) — desplegar el *worker* de colas en sí, decisión de infraestructura fuera del alcance de un `chore/` de documentación.

### Revisión independiente
`doc-reviewer` revisó el diff propio del chore (2 hallazgos, orden cronológico de `CHANGELOG.md` y una imprecisión de redacción, ambos corregidos) y auditó el resto de la documentación no tocada en la primera pasada (4 issues nuevos encontrados: #126-#129). Auditoría aparte de validez de los 49 issues abiertos del repositorio.

---

## 2026-08-31 · Cierre de 1.3b (`REQ-AUTH-003`: MFA — correo como segundo factor, excepciones temporales y administración)

### Nuevo: correo como 2FA, excepciones temporales y pantalla de administración
Partido de `1.3` por tamaño (`OPEN-AUTH-24`). Cuatro piezas: (1) correo como segundo factor de MFA con `DestinationMasker` (enmascarado determinista) y `MfaDeliveryCode` (hash SHA-256, comparación `hash_equals`); (2) excepciones temporales nominales a la obligatoriedad (`MfaExemptionService`, 3 endpoints, tope de 90 días, reapertura automática al caducar); (3) cuatro tareas de mantenimiento programadas (`PurgeMfaEnrollments`/`PurgeMfaFactors`/`PurgeMfaChallenges`/`MaterializeMfaObligations`+`ReopenExpiredMfaExemptions`), cierra issue [#109](https://github.com/pirexia/plataforma-educativa/issues/109); (4) pantalla `/administracion/mfa` (cumplimiento por rol, conmutador `mfa_required` con vista previa, restablecimiento ajeno, gestión de excepciones). Especificación aprobada en `docs/modulos/REQ-AUTH/*.md` Parte D. 288 tests Pest en verde, `pint`/`phpstan` limpios; frontend `eslint`/`vue-tsc`+`build`/`vitest`(20)/`lint:i18n` limpios. Verificado en navegador real (Playwright MCP). Mezclado a `develop` vía PR [#123](https://github.com/pirexia/plataforma-educativa/pull/123) (*squash*, commit `dd68f48`).

### Corregido
- **Media** (revisión independiente `db-reviewer`, [#118](https://github.com/pirexia/plataforma-educativa/issues/118)/[#119](https://github.com/pirexia/plataforma-educativa/issues/119)): `PurgeMfaChallenges`/`PurgeMfaFactors` filtraban sin índice de soporte — el índice de `1.3` quedó sobre la columna equivocada. Corregido con `CREATE INDEX CONCURRENTLY` el mismo día.
- **Media** (revisión independiente `doc-reviewer`, [#121](https://github.com/pirexia/plataforma-educativa/issues/121)/[#122](https://github.com/pirexia/plataforma-educativa/issues/122)): `funcional.md` afirmaba "ninguna dependencia nueva" — sin documentar el primer uso real de `@tanstack/vue-table` (ya en el stack aprobado) ni la dependencia genuinamente nueva `@vueuse/core`, ambas traídas por la pieza 3 — y `admin.md` citaba una pantalla inexistente. Ambos corregidos el mismo día.
- **Proceso**: issue [#110](https://github.com/pirexia/plataforma-educativa/issues/110), `Route::getController()` cacheaba el controlador entre peticiones simuladas de un mismo test Pest con dependencia `scoped()` — corregido en `tests/Pest.php`, no explotable en producción.
- De paso, cerrado en GitHub el issue [#115](https://github.com/pirexia/plataforma-educativa/issues/115) (403 de autorrestablecimiento sin `detailKey`): ya resuelto desde la pieza 2, había quedado abierto por descuido.

### Diferido a propósito (issues abiertos)
[#116](https://github.com/pirexia/plataforma-educativa/issues/116) (Baja) tabla de cumplimiento visible antes de elegir rol · [#117](https://github.com/pirexia/plataforma-educativa/issues/117) (Baja) `/mfa-exemptions` sin *rate limit* propio, no explotable · [#120](https://github.com/pirexia/plataforma-educativa/issues/120) (Baja) pantalla sin test automatizado.

### Revisión independiente
`security-reviewer` sin hallazgos Crítica/Alta (1 Baja diferida). `db-reviewer`/`doc-reviewer`: 3 hallazgos Media, todos corregidos el mismo día. Detalle completo en `docs/historial/1.3b-mfa-correo-excepciones.md`.

---

## 2026-08-27 · Cierre de 1.3 (`REQ-AUTH-003`: MFA — TOTP, obligatoriedad por rol y restablecimiento)

### Nuevo: backend y frontend completos de MFA
TOTP con códigos de respaldo, login en dos pasos, obligatoriedad por rol (`MfaPolicy`, resolución multi-rol), período de gracia y muro de sesión restringida, `PATCH /roles/{public_id}` acotado a `mfa_required` (permiso nuevo `rol.actualizar` en `REQ-CORE`), listado de cumplimiento (agregado e individualizado), restablecimiento por administrador. 6 tablas nuevas + 2 modificaciones aditivas, 10 endpoints en `Auth` + 1 en `Core`, 4 pantallas (`/entrar` en dos pasos, `/cuenta/seguridad`, `/cuenta/seguridad/obligatorio`, `QrCode.vue`). `ADR-041` aprueba `pragmarx/google2fa ^9.1` (backend) y `uqr ^0.1.3` (frontend), ambas envueltas tras interfaz propia. Especificación aprobada en `docs/modulos/REQ-AUTH/funcional.md §C` (`OPEN-AUTH-18` a `26` resueltas). Correo como segundo factor y excepciones temporales nominales diferidos a `1.3b`. 320 tests Pest en verde, `pint`/`phpstan` limpios; frontend (`eslint`/`lint:i18n`/`vue-tsc`+`build`/`vitest`) en verde; `composer audit`/`npm audit` sin vulnerabilidades. Mezclado a `develop` vía PR [#107](https://github.com/pirexia/plataforma-educativa/pull/107) (*squash*, commit `cd13e8a`).

### Corregido
- **Media** ([#96](https://github.com/pirexia/plataforma-educativa/issues/96)): `compose.yaml` no fijaba `target: dev` en el *build* multi-etapa de `api`/`web` desde `0.9b` — cualquier reconstrucción rompía el entorno de desarrollo local.
- **Media** (revisión independiente `db-reviewer`, [#98](https://github.com/pirexia/plataforma-educativa/issues/98)): migración de `login_attempts` sin `NOT VALID`/`VALIDATE CONSTRAINT`, riesgo de bloqueo en despliegue con volumen.
- **Media** (revisión independiente `doc-reviewer`, 5 hallazgos, [#99](https://github.com/pirexia/plataforma-educativa/issues/99)-[#103](https://github.com/pirexia/plataforma-educativa/issues/103)): `funcional.md`/`SYSADMIN.md`/`RUNBOOK.md`/manual de administrador sin reconciliar tras la partición 1.3/1.3b; `QrCode.vue` sin implementar `fill="currentColor"` como fija `ADR-041`.
- **Baja** (2 hallazgos, [#104](https://github.com/pirexia/plataforma-educativa/issues/104)-[#105](https://github.com/pirexia/plataforma-educativa/issues/105)): convención de FK y cifra incorrecta en `operacion.md`.
- **Proceso**: un subagente `implementer` relanzado tras un corte de cuota recortó `GET /mfa-compliance/users` del alcance ya aprobado, sin autorización — corregido, y motivó una norma nueva en `CLAUDE.md §3` (v2.2.1): relanzar un subagente de ejecución no es licencia para decidir alcance.

### Diferido a propósito (issues abiertos)
[#106](https://github.com/pirexia/plataforma-educativa/issues/106) suite Pest completa agota el `memory_limit` de 128M del PHP CLI en local, no afecta a CI · `1.3b` (correo como 2FA, excepciones temporales, pantalla de administración) → paso propio posterior.

### Revisión independiente
`security-reviewer` sin hallazgos. `db-reviewer`/`doc-reviewer`: 8 hallazgos, todos corregidos en la misma sesión. Detalle completo en `docs/historial/1.3-mfa-obligatorio-por-rol.md`.

---

## 2026-08-26 · Cierre de 1.2b (`REQ-AUTH-005` puntos 2-4: sesiones activas, cierre remoto y detección de dispositivo)

### Nuevo: panel de sesiones activas y detección de dispositivo nuevo
Puntos 2-4 de `REQ-AUTH-005`, diferidos de `1.2` (issue [#59](https://github.com/pirexia/plataforma-educativa/issues/59)): listado de sesiones activas, revocación individual y masiva, detección de login desde dispositivo nuevo. 2 migraciones (`user_known_devices`, `user_sessions`, RLS desde el primer día), 3 endpoints de autoservicio, pantalla `/cuenta/sesiones`, 4 idiomas, OpenAPI completo. `ADR-040` (exclusión declarativa del *observer* de auditoría). No incluye geolocalización por IP (`OPEN-AUTH-13`, pospuesta) ni RLS en `sessions` del framework (issue [#81](https://github.com/pirexia/plataforma-educativa/issues/81), endurecimiento futuro). 279 tests backend (1576 aserciones), `pint`/`phpstan` limpios; frontend `eslint`/`vue-tsc`/`lint:i18n`/`vitest` (10/10) limpios. Mezclado a `develop` vía PR [#91](https://github.com/pirexia/plataforma-educativa/pull/91) (*squash*, commit `12fe917`).

### Corregido
- **Alta** (verificación en navegador real, issue [#85](https://github.com/pirexia/plataforma-educativa/issues/85)): `device_known` siempre daba `true`, ningún test unitario lo detectó.
- **Media**: gestión de foco de las confirmaciones de revocar sesión no cumplía WCAG 2.2 AA 2.4.3/2.4.7.
- **Media** (issue [#88](https://github.com/pirexia/plataforma-educativa/issues/88)): fix de `security-reviewer` sobre `Auth::guard('web')->logout()` quedó incompleto y sin verificar (fallo de entorno propio del agente) — reproducía la misma violación de FK por otra vía; corregido y verificado de verdad, junto con el test de regresión que lo enmascaraba.
- **Media** (`db-reviewer`): los tres jobs de retención no tenían test — añadido `AuthRetentionJobsTest.php`.
- **Media** (`doc-reviewer`, issue [#89](https://github.com/pirexia/plataforma-educativa/issues/89)): `SYSADMIN.md`/`PRIVACY.md` sin el inventario de cookies comprometido en la especificación.
- **Proceso**: dos de los tres agentes de revisión lanzados con `isolation: "worktree"` se crearon desde un commit muy antiguo, sin el código del módulo — detectado tras más de una hora, parados y relanzados sin aislamiento. Motivó la norma de verificar `git log --oneline -1` en todo *worktree* de agente antes de dar por buena una revisión.

### Diferido a propósito (issues abiertos)
[#81](https://github.com/pirexia/plataforma-educativa/issues/81) (Media) `tenant_id`/RLS en `sessions` del framework · [#89](https://github.com/pirexia/plataforma-educativa/issues/89) (Media) plantilla de despliegue incompleta, no bloquea (`OPEN-11`) · [#90](https://github.com/pirexia/plataforma-educativa/issues/90) (Baja) literal sin traducir, decisión de convención pendiente.

### Revisión independiente
`db-reviewer`/`security-reviewer`/`doc-reviewer`, con el incidente de *worktrees* descrito arriba. Todos los hallazgos corregidos y verificados antes de mezclar. Detalle completo en `docs/historial/1.2b-sesiones-activas.md`.

---

## 2026-08-25 · Cierre de 1.2 (`REQ-AUTH`: autenticación local y sesiones)

### Nuevo: backend y frontend completos de `REQ-AUTH` (10 endpoints, 6 pantallas)
Migraciones, dominio, infraestructura y capa HTTP de los 10 endpoints (login, logout, activación de cuenta, recuperación/restablecimiento/cambio de contraseña, desbloqueo de cuenta, `me`). Cliente TS (`api`/`types`/`i18n`/composables) y las 6 pantallas públicas correspondientes en `apps/web`, enrutadas. OpenAPI (`apps/api/openapi/paths/auth.yaml`) con paridad 1:1 contra `route:list`. Especificación aprobada previamente (`docs/modulos/REQ-AUTH/`, `ADR-039`). 241 tests en verde, `pint`/`phpstan` limpios; frontend (`eslint`/`lint:i18n`/`vue-tsc`+`build`/`vitest`/Playwright e2e) en verde. Mezclado a `develop` vía PR [#76](https://github.com/pirexia/plataforma-educativa/pull/76) (*squash*, commit `0d34587`).

### Corregido
- **Severidad Crítica** ([#62](https://github.com/pirexia/plataforma-educativa/issues/62)): `SessionEnvironmentGuard` (nuevo, corre en todos los entornos) tumbaba `plataforma-api` porque `apps/api/.env` traía `SESSION_LIFETIME=120` (valor del starter kit) frente al mínimo de 480 que exige `REQ-AUTH`. Parcheado en `compose.yaml`, pendiente de trasladar a `.env` real. Documentado en `SYSADMIN.md §2c`/`RUNBOOK.md §2.2`.
- **Severidad Alta** ([#63](https://github.com/pirexia/plataforma-educativa/issues/63)): `login` se auditaba con `actor_type='anonymous'` porque `AuditRecorder::record()` corría antes de `Auth::login()`.
- **Severidad Alta** ([#67](https://github.com/pirexia/plataforma-educativa/issues/67)): colisión entre *worktrees* de subagentes trabajando la misma rama revirtió parte de un commit del frontend; detectada y corregida por el propio subagente.
- **Severidad Alta** ([#71](https://github.com/pirexia/plataforma-educativa/issues/71)): el login por navegador daba `404`/`419` pese a que una verificación con `curl` lo daba por bueno — esa verificación golpeaba el host de tenant correcto, no el camino real del navegador, que ni siquiera puede leer una cookie fijada por un host distinto al de la propia página (`document.cookie`, ignora `SameSite`/CORS del todo). Corregido sirviendo la SPA desde el mismo host que la API (`CORS_ALLOWED_ORIGINS` + `apps/web/vite.config.ts` `server.allowedHosts`), verificado de extremo a extremo replicando la petición exacta del navegador y confirmado en un navegador real.
- **Severidad Alta** ([#72](https://github.com/pirexia/plataforma-educativa/issues/72)): `apps/web/node_modules` desincronizado de `package-lock.json` (faltaba `vue-i18n` y ~170 paquetes), la SPA no cargaba. `npm ci` dentro del contenedor.
- **Severidad Alta** ([#73](https://github.com/pirexia/plataforma-educativa/issues/73), hallazgo de la revisión de seguridad independiente): los tokens de restablecimiento/desbloqueo en claro persistían indefinidamente en `failed_jobs` si el correo agotaba sus 5 reintentos — vía real de *account takeover*. `ShouldBeEncrypted` en los dos *jobs* de correo, más `queue:prune-failed --hours=24` programado como segunda capa.
- **Severidad Alta** ([#74](https://github.com/pirexia/plataforma-educativa/issues/74), misma revisión): `GET /auth/csrf-cookie` era el único de los 6 endpoints anónimos sin límite de tasa, pese a tener el *bucket* ya definido y sin usar — vector de agotamiento de recursos. Corregido invocándolo.
- **Severidad Alta** ([#75](https://github.com/pirexia/plataforma-educativa/issues/75)): mismo hallazgo que #73 en `SendInvitationEmail` (`REQ-CORE`, 1.1 ya mezclado) — corregido de paso por ser trivial y de la misma naturaleza, con su propio test de regresión.
- **Severidad Media** ([#64](https://github.com/pirexia/plataforma-educativa/issues/64)): `actingAs()` no fijaba `pge_tenant_id`; `VerifySessionTenant` (nuevo en 1.2) rompía los ~20 ficheros de test de 1.1. Corregido sobrescribiendo `actingAs()` en `Tests\TestCase`, sin tocar tests de `REQ-CORE`.
- **Severidad Media** ([#66](https://github.com/pirexia/plataforma-educativa/issues/66)): faltaba la guarda de arranque de `AUTH_PASSWORD_MIN_LENGTH`/`AUTH_BCRYPT_ROUNDS ≥ 12` que `operacion.md` ya documentaba como existente.
- **Severidad Media** ([#68](https://github.com/pirexia/plataforma-educativa/issues/68)): faltaba `apps/api/config/cors.php` — sin él, Laravel aplicaba `allowed_origins: ['*']`/`supports_credentials: false`, incompatible con `credentials: 'include'` (usado en todas las peticiones desde `client.ts`). Nuevo fichero con orígenes explícitos vía `CORS_ALLOWED_ORIGINS` (`SYSADMIN.md §2c`).
- **Severidad Media** (7 hallazgos de la revisión de documentación independiente, todos corregidos): `ADR-039` sin aplicar del todo en `datos.md` de `REQ-AUTH`/`REQ-CORE` (vocabulario de `audit_logs`); cadena de *middleware* de `api.md §8` sin `EncryptCookies`; `auth:grant-lockout-permissions` sin nombre real en `operacion.md`; `VITE_API_URL`/#71 sin reflejo en `SYSADMIN.md`/`RUNBOOK.md`; `admin.md` sin las dos capacidades nuevas de `administrador_centro` (cuentas bloqueadas, tiempo de sesión); un comentario de código con el recuento de endpoints viejo.

### Diferido a propósito (issues abiertos)
[#59](https://github.com/pirexia/plataforma-educativa/issues/59) resto de `REQ-AUTH-005` → `1.2b` · [#60](https://github.com/pirexia/plataforma-educativa/issues/60) `ValidationErrorFormatter` antepone "core." fuera de su módulo · [#61](https://github.com/pirexia/plataforma-educativa/issues/61) reutilización de `UnlockReason::Correo` a falta de un 4º valor · [#65](https://github.com/pirexia/plataforma-educativa/issues/65) manuales de usuario sin las pantallas nuevas · [#69](https://github.com/pirexia/plataforma-educativa/issues/69) `CA-AUTH-060`-`063` sin test automatizado · [#71](https://github.com/pirexia/plataforma-educativa/issues/71) parche temporal fijado a un único tenant de desarrollo, decisión definitiva pendiente.

### Revisión independiente
`security-reviewer`/`doc-reviewer` lanzados dos veces (la primera tanda fue interrumpida por el usuario sin resultado, relanzada de cero). Sin hallazgos Crítico/Alto sin corregir al final. Aislamiento de tenant y autorización denegar-por-defecto verificados activamente (tests cruzados de tenant reales), no solo asumidos. Nota honesta pendiente, igual que en el PR #56 de 1.1: no se pudo verificar el resultado de `ci-api.yml`/`ci-web.yml` sobre el PR antes de mezclar (mismo límite de permisos del token de `gh`) — mezclado confiando en una verificación local más completa que la de 1.1 (incluye Playwright e2e y `npm audit`, que 1.1 no llegó a ejercitar). Detalle completo en `docs/historial/1.2-auth-local-sesiones.md`.

---

## 2026-08-22 · Cierre de 1.1 (`REQ-CORE`: tenants y usuarios)

### Nuevo: API completa de tenants y usuarios
Configuración de centro, usuarios, invitaciones, importación masiva con idempotencia (`RequireIdempotencyKey`, `ADR-038 §8`), roles/permisos/módulos de solo lectura, auditoría+exportación, activos de marca (validación de tipo real por contenido, saneado de SVG). Sin pantallas todavía (`OPEN-CORE-02`, se completan en 1.8). `ADR-038` (convenciones REST) escrito antes de implementar. 76 `CA-CORE-*` con test propio, 183/183 en verde. OpenAPI completo (`components.yaml` + `paths/core.yaml`, 33 operaciones), cliente TS (`apps/web/src/modules/core/`). Mezclado a `develop` vía PR [#56](https://github.com/pirexia/plataforma-educativa/pull/56) (*squash*, commit `d32e4e9`).

### Corregido
- **Severidad Alta**: fallo preexistente de `TenancyServiceProvider` ([#49](https://github.com/pirexia/plataforma-educativa/issues/49)) que vaciaba el contexto de tenant tras cualquier *job* con `QUEUE_CONNECTION=sync`.
- **Severidad Media** (revisión independiente `security-reviewer`/`doc-reviewer`): [#53](https://github.com/pirexia/plataforma-educativa/issues/53) faltaban tests de aislamiento cruzado entre tenants para `/user-imports/*` y `assets/{kind}`; cinco hallazgos de coherencia de documentación (ejemplo: `"total": 17` en vez de 16 tras corregir [#48](https://github.com/pirexia/plataforma-educativa/issues/48)).
- **Severidad Media** ([#50](https://github.com/pirexia/plataforma-educativa/issues/50)): `IdempotencyKey` estaba fuera de su bounded context (`App\Models` en vez de `App\Modules\Core\...`).
- **Severidad Media** ([#51](https://github.com/pirexia/plataforma-educativa/issues/51)): Larastan no reconocía las columnas reales de los modelos de tenant (`phpstan analyse` 234→0 con `barryvdh/laravel-ide-helper` + `@mixin` en los 15 modelos).
- **Severidad Media** ([#55](https://github.com/pirexia/plataforma-educativa/issues/55)): `TenantMigrationTest` fallaba en una base de datos recién provisionada; reescrito para probar el comportamiento correcto.
- **Diferido a 1.2 a propósito** ([#18](https://github.com/pirexia/plataforma-educativa/issues/18)): falta un `PasswordBrokerRepository` propio con tenant en la recuperación de contraseña.

`security-reviewer` no encontró hallazgos Crítico/Alto. Nota honesta pendiente: no se pudo verificar el resultado de `ci-api.yml`/`ci-web.yml` sobre el PR antes de mezclar (el token de `gh` de esta sesión no tenía permiso para leer *check runs*, 403) — se mezcló confiando en la verificación local exhaustiva. Detalle completo, subpaso a subpaso, en `docs/historial/1.1-core-tenants-usuarios.md`.

---

## 2026-08-19 · Cierre de 0.9b (portabilidad del despliegue)

### Nuevo: Containerfiles multi-etapa, `build-images.yml`, `infra/quadlet/`
Implementa `ADR-037`. `infra/containers/{api,web}/Containerfile` con etapas `base`/`dev`/`build`/`prod` (FrankenPHP en modo clásico para la API, nginx solo de estáticos para la SPA). `.github/workflows/build-images.yml`: publica en GHCR con etiquetado por `sha`/`develop`/`vX.Y.Z`, retención desde el primer commit, guarda `proxy_pass`, `quadlet-lint`, y gate de CI en verde para tags de versión. Diez unidades Quadlet en `infra/quadlet/` conformes a `ADR-028`. Banco de pruebas local `infra/compose/compose.prodlike.yaml`, instalador `infra/install.sh`, convención de secretos por `EnvironmentFile=` (dos ficheros: `plataforma.env.example` para la API, `plataforma-postgres.env.example` para PostgreSQL).

Las tres pruebas obligatorias de `ARCHITECTURE.md §4.3` verificadas de verdad en WSL2 con `compose.prodlike.yaml` (el arranque nativo con `systemctl --user` quedó bloqueado por un problema de permisos preexistente del host, documentado en `SYSADMIN.md §6.2` sin forzarlo).

### Corregido
- **Severidad Media** (revisión independiente de `doc-reviewer`): `build-images.yml` no exigía CI en verde para tags de versión pese a que `ADR-037 §5.3` lo fija como obligatorio. Añadido el job `require-ci-green`.
- **Severidad Media** (`doc-reviewer`): `plataforma.env.example` se anunciaba como plantilla completa y le faltaban `APP_URL`/`APP_NAME` — ambas usadas por Laravel con valores por defecto silenciosos (`http://localhost`, `"Laravel"`).
- **Severidad Media** (`doc-reviewer`): numeración rota en `SYSADMIN.md §6` (dos secciones "6.3", ninguna "6.2"). Renumerado y corregidas las referencias cruzadas en `RUNBOOK.md`.
- **Severidad Media** (`doc-reviewer`): `infra/install.sh` recomendaba `enable --now` sobre `plataforma-migrate.service`, una unidad sin sección `[Install]` — corregido a `start`.
- **Severidad Media** ([#35](https://github.com/pirexia/plataforma-educativa/issues/35), `security-reviewer`): sin `.containerignore` en los contextos de construcción — construir la imagen `prod` localmente desde un árbol de desarrollo real copiaría `.env`/claves/`vendor` a la imagen. Añadidos `apps/api/.containerignore` y `.containerignore` (raíz).
- **Severidad Media** ([#36](https://github.com/pirexia/plataforma-educativa/issues/36), `security-reviewer`): `postgres.container` recibía el `EnvironmentFile` completo de la API (`APP_KEY`, `DB_*_PASSWORD`) cuando solo necesita sus propias credenciales de arranque. Separado en `plataforma-postgres.env.example`.

### Diferido a propósito (issues abiertos, severidad Baja, `CLAUDE.md §5`)
[#37](https://github.com/pirexia/plataforma-educativa/issues/37) Redis sin autenticación · [#38](https://github.com/pirexia/plataforma-educativa/issues/38) `minio-data.volume` huérfano hasta `0.10d` · [#39](https://github.com/pirexia/plataforma-educativa/issues/39) (resuelto en este cierre, ver arriba) · [#40](https://github.com/pirexia/plataforma-educativa/issues/40) sin escaneo de vulnerabilidades a nivel de imagen del SO.

---

## 2026-08-18 · Cierre automático de sesión por límite de cuota

### `CLAUDE.md` → 2.1.0
El cierre de sesión por poca cuota (§3) dejaba de disparar hasta que el usuario avisara. Ahora se dispara solo, en cuanto el sistema emite el aviso de "usage limit approaching": termina el paso en curso, comitea/pushea, actualiza `memory.md`/`PLAN-IMPLEMENTACION.md`, y programa la vuelta. Mecanismo detallado en el skill `cierre-de-sesion`.

### `cierre-de-sesion` → 1.1.0
Nueva sección "Cierre automático por límite de cuota": no hay herramienta para consultar el porcentaje de cuota ni la hora de reset (hay que preguntársela al usuario si no se sabe); `ScheduleWakeup` programa la vuelta, encadenando tramos de máximo una hora si el reset queda más lejos.

### `CLAUDE.md` → 2.1.1 y `cierre-de-sesion` → 1.1.1
Investigado (subagente `claude-code-guide`) si el propio aviso de límite trae la hora de reset. Según fuentes de terceros, no confirmadas en documentación oficial de Anthropic, el aviso de **límite alcanzado** (distinto del de aproximación visto en esta sesión, que no la trae) sí la incluiría: `"...resets 3:45pm"` (5h) / `"...resets Mon 12:00am"` (semanal). Añadidos los patrones de extracción como primer intento; si no coinciden, se sigue preguntando al usuario.

### `CLAUDE.md` → 2.1.2 y `cierre-de-sesion` → 1.1.2
Corrección tras confirmación real del usuario (app Android): la hora de reset la muestra el **cliente**, en una tarjeta de interfaz propia, no un texto que llegue al modelo. Retirados los patrones de extracción de la versión anterior (no aplicables); la regla vuelve a ser preguntar siempre, salvo que el usuario ya la haya dado en la conversación.

---

## 2026-08-18 · Cierre de 0.13 (plantillas de documentación)

### Nuevo: `SECURITY.md`, `PRIVACY.md`, `RUNBOOK.md`, `CONTRIBUTING.md`
Los cuatro documentos raíz que exige `CLAUDE.md` §6 y que todavía faltaban. `docs/modulos/_PLANTILLA/` ya existía desde el paso 0.1. Cada documento describe lo que es cierto hoy (fase 0, sin datos reales) y marca explícitamente como pendiente lo que depende de un bloqueante todavía abierto (`OPEN-07` para `PRIVACY.md`, `OPEN-11`/`OPEN-10` para `RUNBOOK.md`, `OPEN-08` para el contacto de seguridad de `SECURITY.md`) en vez de rellenarlo con una suposición.

---

## 2026-08-18 · Cierre de 0.8 (modelo de datos núcleo)

### Nuevo: `docs/adr/ADR-034-modelo-de-datos-nucleo.md`
Diseñado por el subagente `architect` (Opus). `Person`/`User` como identidad y credencial separadas; esquema completo de `Role`/`Permission` desde ahora con el resolutor granular diferido a 1.5; `AuditLog` polimórfica append-only con redacción por modelo; `AcademicYear` con `academic_year_id` obligatorio-o-ausente, nunca nullable; `ModuleSubscription` con catálogo de módulos materializado desde el código. Dos preguntas abiertas sin resolver a propósito (`OPEN-12`, supresión frente a auditoría inmutable; `OPEN-13`, columnas definitivas de `Person`), ninguna bloqueante de 0.8.

### Nuevo: `apps/api` — siete tablas del núcleo, modelos y comando de sincronización
`academic_years`, `people`, `users` (rehecha), `roles`/`role_user`, `permissions`/`permission_role`, `modules`/`module_subscriptions`, `audit_logs`. `TenantMigration` gana `tenantTableAppendOnly()` y `tenantForeignId()`. `TenantModel` gana `SoftDeletes` y `RecordsAuthorship`; nuevo `AppendOnlyModel`. Modelos `Person`, `User`, `Role`, `Permission`, `AcademicYear`, `ModuleSubscription`, `AuditLog`, con *morph map* forzado. Comando `platform:sync-registry`, idempotente. 94 tests en `tests/Feature/Core/` y `tests/Feature/Tenancy/`, incluida una batería de invariantes de esquema generales (no hardcodeadas por tabla) que amplía la de `ADR-033` §10.

### Corregido
- **Seguridad, severidad Alta**: `password_reset_tokens` del starter kit de Laravel usaba `email` como clave primaria global — con `users.email` único *por tenant*, un token del centro A servía para la cuenta homónima del centro B (toma de control de cuenta entre tenants). Ahora clave primaria compuesta `(tenant_id, email)`.
- **Seguridad, severidad Alta** ([#17](https://github.com/pirexia/plataforma-educativa/issues/17)): las tablas append-only solo revocaban `UPDATE, DELETE` a `plataforma_app`; `plataforma_platform` (BYPASSRLS) conservaba privilegio completo, vaciando la garantía de inmutabilidad de `audit_logs` para la conexión de backoffice. Revocado también para `plataforma_platform`.
- **Severidad Media** ([#16](https://github.com/pirexia/plataforma-educativa/issues/16)): `tenants.slug` (0.7) con índice único no parcial — un tenant dado de baja bloqueaba su slug para siempre.
- **Severidad Media** ([#19](https://github.com/pirexia/plataforma-educativa/issues/19)), hallazgo de la revisión independiente de `db-reviewer`/`security-reviewer` tras el autoinforme del *fork* de implementación: el test que comprueba que las tablas de referencia no dan privilegios de escritura solo miraba `plataforma_app` — mismo punto ciego que dejó pasar el #17. Generalizado a los dos roles de aplicación.
- **Severidad Media** ([#20](https://github.com/pirexia/plataforma-educativa/issues/20)), mismo origen: `people_tenant_document_unique` no impedía dos personas del mismo tenant con el mismo `document_number` si `document_type` quedaba `NULL` en ambas (PostgreSQL trata cada `NULL` como distinto). `CHECK` nuevo que empareja la nulabilidad de las dos columnas.
- **Diferido a 1.2** ([#18](https://github.com/pirexia/plataforma-educativa/issues/18)): falta un `PasswordBrokerRepository` propio que filtre por tenant — hoy la corrección de `password_reset_tokens` depende solo de RLS. Fuera de alcance de "modelo de datos núcleo".

### Bugs propios encontrados y corregidos durante la implementación
Detalle completo, subpaso a subpaso, en `docs/historial/0.8-modelo-de-datos-nucleo.md`.

---

## 2026-08-17 · Tarde · Cierre de 0.7 (núcleo multi-tenant)

### Nuevo: `docs/adr/ADR-033-implementacion-del-aislamiento-multi-tenant.md`
Diseñado por el subagente `architect` (Opus), aprobado por el usuario. RLS de PostgreSQL como barrera primaria, scope de Eloquent como ergonomía secundaria, tres roles de base de datos sin `SUPERUSER`, claves foráneas compuestas `(tenant_id, id)`, veto a PgBouncer en modo *transaction*, suite de tests sobre PostgreSQL real.

### Nuevo: `apps/api` — infraestructura de tenancy completa
`app/Support/Tenancy/` (`TenantContext`, `Tenant`, `TenantModel`, `BelongsToTenant`, `TenantScope`, `TenantHost`, `TenantStorage`, `TenantMigration`, `RunsPerTenant`, `TenantStatus`), `app/Http/Middleware/ResolveTenant.php`, `app/Providers/TenancyServiceProvider.php`. Tres conexiones de base de datos (`pgsql`/`pgsql_owner`/`pgsql_platform`), `config/tenancy.php` (dominio base, registro de tablas compartidas), primeras claves de `lang/*/tenancy.php`. `infra/containers/postgres/init/` provisiona el esquema `app`, la función `app.current_tenant_id()` y los tres roles. 47 tests en `tests/Feature/Tenancy/`, incluida la batería completa de diez tests de `ADR-033` §10.

### Bugs propios encontrados y corregidos durante la implementación
No relacionados con el diseño de 0.7 en sí, pero descubiertos verificándolo:
- `apps/api/phpunit.xml` sin `force="true"` en `<env>`: la suite llevaba desde el paso 0.4 corriendo contra la base de datos de desarrollo real, no contra la configuración de test documentada.
- `infra/containers/api/Containerfile` sin `--no-reload` en `php artisan serve`: toda petición HTTP real devolvía 500 vacío sin log porque Laravel filtraba el entorno del proceso hijo del servidor embebido.
- `failed_jobs` tenía privilegios completos para `plataforma_app` pese a no tener `tenant_id`/RLS (fuga potencial entre tenants en los registros de fallos).
- `Queue::$createPayloadCallbacks` es estático de clase: se acumulaba en cada reconstrucción de la aplicación (cada test de Laravel, o un futuro Octane en producción).
- `PendingDispatch` envía el job en su `__destruct()`: si `dispatch()` es la expresión de retorno de un closure pasado a `TenantContext::runFor()`, el envío ocurre después de que el contexto se restaure.

Detalle completo del proceso, subpaso a subpaso, en `memory.md`.

---

## 2026-08-17 · Corrección de coherencia: `ADR-024`/`ADR-027`/`ADR-030`

### `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` → 3.1.1
Al preparar el diseño de 0.7 se detectó que la sección 18 (fuente de verdad canónica de `ADR-001` a `ADR-027`, `CLAUDE.md` §6.3) tenía la entrada de `ADR-024` desactualizada — seguía diciendo "Docker Compose sobre VPS europeo" sin reflejar que `ADR-027` lo sustituyó — y que **`ADR-027` no aparecía en ningún sitio del documento**, pese a ser canónico ahí por numeración. Añadida la entrada de `ADR-027` y anotada en ambas la cadena de sustituciones real: `ADR-024` → `ADR-027` (host inicial: VM RHEL 10/VMware, no VPS) → `ADR-030` (sustituye a `ADR-027` para la etapa de desarrollo E0: WSL2 en equipo personal; la VM VMware queda como candidata a preproducción).

### `ARCHITECTURE.md` → 2.0.1
Las entradas de `ADR-024` y `ADR-027` en el apéndice de ADR contradecían a la tabla de §4.2 del mismo documento (que ya reflejaba correctamente WSL2 en E0 desde el cierre de `ADR-030`). Sincronizadas ambas entradas con la cadena de sustituciones.

### `README.md` → 2.4.1
La fila "Host inicial: VM VMware" de la tabla de stack contradecía directamente a la fila "Desarrollo: WSL2 en equipo personal" dos filas por encima. Sustituida por "Alojamiento del piloto: pendiente de decidir (`OPEN-11`)".

### `memory.md`
Nota añadida en la fila de `ADR-027` de la tabla de decisiones señalando la sustitución por `ADR-030` en desarrollo.

---

## 2026-08-14 · Cierre de 0.3 y 0.5, MCP de Boost y Playwright

### Nuevo: `apps/web` (Vue 3 + TypeScript + Vite)
- Tailwind v4 + shadcn-vue inicializados (tema con variables CSS, sin la fuente de Google que trae la plantilla por defecto: llamada a un tercero en cada carga, cuestión de privacidad en un producto que trata datos de menores).
- `vue-router`, `AppLayout` + `HomeView`, `src/modules/` (espejo de `apps/api/app/Modules/`, vacío hasta 1.1).
- Cliente API propio (`src/api/client.ts`, `fetch` nativo sin librería) con `ApiError` tipado y `credentials: 'include'` ya previsto para la cookie de sesión (`ADR-025`).
- ESLint (flat config) + Prettier, Vitest (4 tests) y Playwright (1 e2e, verificado contra el servidor real) en verde.

### `compose.yaml` → 0.3.0
Servicio `web` añadido al perfil reducido (`infra/containers/web/Containerfile`), que queda con `postgres`+`redis`+`api`+`web` por defecto.

### `.mcp.json` (nuevo, raíz del repo)
Laravel Boost (`laravel/boost` en `apps/api`, `php artisan boost:install --mcp`) y Playwright (`@playwright/mcp`). El instalador de Boost escribió el comando envuelto en `wsl.exe`; corregido a mano porque Claude Code ya corre dentro de WSL2.

### `SYSADMIN.md` → 0.3.0
Documentado el servicio `web` y por qué `VITE_API_URL` no se sobrescribe dentro del contenedor (quien hace la petición es el navegador de Windows, no el contenedor).

---

## 2026-08-13 · Tarde · Cierre de 0.4

### Nuevo: `apps/api` (Laravel 13, PHP 8.4)
Primer código de aplicación del repositorio.
- `app/Modules/` con la convención de bounded context (`Domain`, `Application`, `Infrastructure`, `Http`, `INV-007`) y autodescubrimiento de `ServiceProvider` vía `App\Support\Modules\ModuleServiceProviderDiscovery`, sin registro manual en `bootstrap/providers.php`. Vacío hasta el paso 1.1.
- `GET /api/health`, documentado en `apps/api/openapi.yaml`.
- Pest configurado (4 tests, 8 aserciones) y Larastan nivel 6, ambos en verde.
- `routes/web.php` vaciado y `resources/views/welcome.blade.php` eliminada: backend puramente API (`INV-006`).

### `compose.yaml` → 0.2.0
Servicio `api` añadido al perfil reducido (`infra/containers/api/Containerfile`). Corregido un fallo propio de la imagen: purgar `libpq-dev`/`libzip-dev` con `--auto-remove` tras compilar las extensiones se llevaba las librerías compartidas en tiempo de ejecución (`libpq.so.5`, `libzip.so.4`) y `pdo_pgsql`/`zip` dejaban de cargar; el healthcheck no lo detectaba porque no toca la base de datos.

### `SYSADMIN.md` → 0.2.0
Documentado el servicio `api`: puerto, montaje de volumen, variables de entorno sobrescritas para resolución de nombres dentro de la red de contenedores.

---

## 2026-08-13 · Cierre de pasos 0.1, 0.2 y 0.3

### Nuevo: `LICENSE`
Propietaria, todos los derechos reservados. Titularidad jurídica definitiva pendiente de `OPEN-07`.

### Limpieza de 0.1
- Eliminado `SKILL.md` suelto en la raíz, duplicado de `.claude/skills/aislamiento-tenant/SKILL.md`.
- `.gitignore`: añadidos patrones de Python (`__pycache__/`, `*.pyc`, entornos virtuales) para `seed/`.

### `docs/SETUP-ENTORNO.md` → 1.3.0
Alta del MCP de GitHub con gestión segura del token (tres ámbitos de configuración, detección de token en claro en `~/.claude.json`), y cuatro pruebas de verificación del paso 0.2, incluida la prueba negativa de la Regla 0.

### Cierre de 0.2
Verificado con las cuatro pruebas de `docs/SETUP-ENTORNO.md` §7.4: MCP de GitHub confirmado creando y cerrando un issue de prueba. Pendiente sin resolver: `spec-writer` no aparece en la lista de subagentes disponibles de esta sesión pese a estar bien definido en `.claude/agents/spec-writer.md`.

### Nuevo: `compose.yaml`, `.env.example`, `SYSADMIN.md` → 0.1.0
Paso 0.3: perfil reducido (`postgres` + `redis` por defecto, `minio` tras `--profile full`), red externa `plataforma-net` sin destruir (`ADR-028`). Verificado arrancando ambos contenedores en estado `healthy`. `api`, `web` y el servicio de PDF quedan fuera a propósito: los dos primeros por los pasos 0.4/0.5, el tercero por no tener motor decidido.

---

## 2026-08-12 · Tarde

### `.gitignore` → corrección
- **Excluía `marketing/*.pdf`**, lo que habría dejado fuera del repositorio la propia presentación comercial. Ahora solo se ignoran los renders intermedios (`slide-*.jpg`) y `build/`.

### Nuevo: `docs/SETUP-ENTORNO.md` → 1.1.0
Guía completa de puesta en marcha: WSL2 con límite de recursos, claves SSH, GitHub, Podman con red externa, Node, PHP, Claude Code, repositorio con ramas protegidas, plugins y MCP. Con lista de comprobación y problemas frecuentes.
- **1.1.0**: punto 6.3 reescrito con el árbol completo de los 53 ficheros, tabla de qué no se sube y verificación de recuento.

### Nuevo: `marketing/`
Presentación comercial de 15 diapositivas. Nombre de marca **provisional**.

### `PLAN-IMPLEMENTACION.md` → 2.2.0
- Paso **0.10f** (presentación comercial) marcado como completado.
- Nuevo paso **0.11b**: web publicitaria.
- Nuevo paso **0.11c**: identidad de marca, bloqueante de la web.

### `README.md` → 2.1.0
- Índice ampliado con la guía de entorno, el generador y marketing.

---

## 2026-08-12

### `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` → 3.1.0
- **`ADR-032`**: fuente única de autorizaciones de recogida de menores. El concepto estaba definido dos veces —en `REQ-PRL-004` (fase 3) y en `REQ-TRAN-005` (fase 2)— con listas separadas que podían divergir.
- Nuevo **`REQ-FAM-UNIT-005`**: lista maestra de personas autorizadas, con foto y documento, en fase 1.
- `REQ-PRL-004` reducido al proceso operativo de entrega y **adelantado a fase 1**.
- `REQ-TRAN-005` pasa a consumir la lista maestra.

### `PLAN-IMPLEMENTACION.md` → 2.1.0
- Nuevo paso **1.14b**, marcado como crítico.

### Skills
- `datos-personales` → 1.1.0: sección de autorizaciones de recogida.

### `seed/`
- Generador de datos sintéticos ejecutable, con verificador. Tres centros generados.
- Autorizaciones de recogida trasladadas de la suscripción de transporte a la unidad familiar.

---

## 2026-08-11 · Tarde

### `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` → 3.0.0

Versión mayor: cambia el entorno de trabajo y se reordena una fase.

- **`REQ-TRAN` (transporte escolar) reescrito**: de 3 requisitos genéricos a 12. Reubicado de COULD/fase 4 a **SHOULD/fase 2** (`ADR-031`). Incorpora autorizaciones de recogida, registro de subida y bajada con alerta de discrepancia, acompañante de ruta, certificación negativa del RCDS con bloqueo, empresa como encargado de tratamiento e integración en la factura mensual.
- **Nuevo módulo `REQ-SEED`** (datos de demostración), MUST de fase 1: tres centros ficticios de régimen distinto, entre 300 y 1.200 alumnos, plantilla completa de personal, convención de datos sintéticos y bloqueo en producción.
- `ADR-030`: entorno de desarrollo en WSL2 y separación respecto al alojamiento.
- `ADR-031`: alcance y fase del transporte escolar.
- Cerrada `OPEN-06` (titularidad de la infraestructura). Abierta **`OPEN-11`**: dónde se aloja el piloto.
- Total: **53 módulos, 31 ADR**.

### `CLAUDE.md` → 2.0.0
- Desarrollo en WSL2 con perfil reducido.
- **Prohibición explícita de datos reales en desarrollo**, sin excepción.
- Convención de datos sintéticos de `REQ-SEED-005`.

### `ARCHITECTURE.md` → 2.0.0
- Etapa E0 pasa a WSL2; nueva etapa E0b para el piloto.
- Tabla de recursos de desarrollo con límite de `.wslconfig` y perfil reducido.
- Advertencia de que las mediciones de rendimiento en el equipo personal son orientativas.

### `PLAN-IMPLEMENTACION.md` → 2.0.0
- Paso 0.3 reescrito para WSL2.
- Paso 0.10 pasa a ser la decisión de alojamiento del piloto (`OPEN-11`).
- Nuevo paso **1.15b**: generador de datos de demostración.
- `REQ-TRAN` movido a fase 2, inmediatamente después del módulo económico.
- Fase 1: 17 módulos.

### `README.md` → 2.0.0
- Regla 0: ningún dato real en desarrollo.
- Tabla de versiones y bloqueantes actualizados.

---

## 2026-08-11 · Mañana

### `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` → 2.6.0
- `ADR-028`: topología de red y dependencias entre contenedores.
- `ADR-029`: identificador público ULID y convenciones de tipos en PostgreSQL.
- Ambos en fichero propio, estrenando la regla de `ADR-026`.
- Nuevas decisiones abiertas `OPEN-06` a `OPEN-10`.

### `CLAUDE.md` → 1.2.0
- Podman en lugar de Docker.
- Reglas de red y dependencias de contenedores.
- Convenciones de esquema de `ADR-029`.
- ADR `001`-`027` canónicos en la sección 18; del `028` en adelante, fichero propio.

### `ARCHITECTURE.md` → 1.2.0
- `ADR-027`: Podman sobre RHEL 10 en VM VMware.
- `ADR-028`: red y dependencias.
- Sección 4.3 de red entre contenedores.
- Tabla de dimensionado para host único.

### `PLAN-IMPLEMENTACION.md` → 1.1.0
- Corregido el conteo de módulos de fase 1: son 16, no 9. Estimación revisada a 6-8 meses.
- Nuevos pasos 0.10b a 0.10e: dominio y DNS, correo transaccional, destino de copias, staging.
- Nuevos pasos 0.12 (marco legal del proveedor) y 0.13 (plantillas de documentación).

### `docs/SETUP-CLAUDE-CODE.md` → 1.2.0
- Evaluación de MCP: se adopta Context7; se descartan Filesystem, Laravel Codebase MCP y Figma; se aplazan Sentry y Kubernetes.
- Restricción de solo lectura y fuera de producción para el MCP de PostgreSQL.
- Adopción de `timescale/pg-aiguide`.
- 10 skills propias y regla de contención.

### `README.md` → 1.1.0
- Creado como punto de entrada e índice.
- Tabla de versiones de documentos.

### Skills
| Skill | Versión |
|-------|---------|
| `aislamiento-tenant` | 1.0.0 |
| `contenedores-y-red` | 1.0.0 |
| `migracion-segura` | 1.0.0 |
| `postgres-rendimiento` | 1.1.0 (convenciones de `ADR-029`) |
| `depuracion` | 1.0.0 |
| `permisos-y-roles` | 1.0.0 |
| `datos-personales` | 1.0.0 |
| `modulo-nuevo` | 1.0.0 |
| `i18n-cuatro-idiomas` | 1.0.0 |
| `cierre-de-sesion` | 1.0.0 |

---

## Anterior

- **2.5.0** · Stack cerrado: Laravel + Vue 3/TS + PostgreSQL. `ADR-023` a `ADR-026`. Cerradas `OPEN-01` a `OPEN-05`.
- **2.4.0** · MFA por rol, módulo de copias de seguridad, despliegue sin interrupción.
- **2.3.0** · Backoffice de Super Administrador.
- **2.2.0** · Primer ciclo de Infantil 0-3, régimen por etapa, cuatro idiomas.
- **2.1.0** · Segmento concertados de Madrid, posicionamiento frente a Raíces.
- **2.0.0** · Reorganización para implementación asistida por IA. 22 módulos nuevos.
- **1.2.0** y anteriores · Versiones iniciales del documento de requisitos.
