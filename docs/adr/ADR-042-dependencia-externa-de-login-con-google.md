# ADR-042 · Dependencia externa para el login con Google: `laravel/socialite` tras `ExternalIdentityProvider`

**Estado**: ACEPTADA
**Fecha**: 2026-08-31
**Resuelve**: la comprobación formal de `CLAUDE.md §1` para la dependencia que necesita `REQ-AUTH-002` (sección 5.2 del documento de requisitos)
**Se apoya en**: `RNF-MANT-007` (toda dependencia externa tras interfaz propia), `INV-006` (API primero), `INV-007` (un módulo no importa código interno de otro), `CLAUDE.md §8` (cookie de sesión `httpOnly`/`Secure`/`SameSite`), la nota de seguridad de `REQ-AUTH-002` sobre `email_verified`
**Afecta a**: paso **1.4** (`REQ-AUTH-002`). Es requisito previo de `implementer`
**No decide**: nada de `1.4b` (`REQ-AUTH-004`, SSO institucional SAML 2.0 / OIDC por tenant), que tiene forma distinta y su propio ADR; ni el modelo de datos, los endpoints ni el flujo de fusión de cuentas, que son de `docs/modulos/REQ-AUTH/*.md`; ni si un proveedor externo que ya hizo MFA exime del nuestro (`funcional.md §C` lo remite explícitamente a 1.4b)
**Precedente que sigue**: `ADR-041`, con el mismo criterio — se prefiere la dependencia que hace *menos* y se deja la decisión en nuestro lado del envoltorio

---

## Contexto

El usuario aprobó el **2026-08-31**, a nivel de producto, usar `laravel/socialite` para el login con Google de `REQ-AUTH-002`. Esa aprobación **no satisface** lo que exige `CLAUDE.md §1`:

> «Prohibido introducir una dependencia nueva sin justificarla y sin comprobar mantenimiento activo, licencia y frecuencia de releases. Toda dependencia externa se envuelve tras una interfaz propia (`RNF-MANT-007`).»

Este ADR es esa comprobación, hecha el **2026-08-31** contra `repo.packagist.org`, `packagist.org`, `api.github.com` y el código fuente de la rama `5.x`, no de memoria ni a partir del informe de nadie. Fija además el nombre y la forma exactos del envoltorio, para que `implementer` no decida arquitectura mientras implementa.

Hay dos razones por las que esto no es un trámite.

La primera: **la nota de seguridad de `REQ-AUTH-002` cuelga entera de un valor que Socialite no expone como campo**. El requisito dice literalmente que *«la fusión automática por email solo es aceptable si el proveedor devuelve `email_verified = true`; en caso contrario debe requerirse confirmación explícita desde la cuenta local»*. Ese `email_verified` **no** está en `Laravel\Socialite\Two\User`: hay que sacarlo del array crudo. Un `$user->user['email_verified'] ?? false` repetido en tres sitios es una vía de apropiación de cuenta esperando a que alguien lo simplifique.

La segunda: `REQ-AUTH-002` es **el primer punto del sistema donde una identidad externa se convierte en sesión local con roles y datos de menores detrás**. Lo que entre por ahí no lo valida ninguna contraseña nuestra.

Estado de partida verificado en `apps/api/composer.lock`: **78 paquetes de producción**, ninguno de OAuth. `guzzlehttp/guzzle 7.15.3` ya está presente. Restricciones del entorno: `php: ^8.4`, `laravel/framework: ^13.17`. Sesión: `config/session.php` con `same_site` = `lax` por defecto (`SESSION_SAME_SITE`), `http_only` = `true`.

---

## 1 · Opciones reales

Cuatro, y todas se han mirado con datos:

1. **`laravel/socialite`** — el cliente OAuth1/OAuth2 de primera parte del framework, con proveedor Google incluido.
2. **`league/oauth2-client` + `league/oauth2-google`** — cliente OAuth2 genérico de The League, con el proveedor de Google en paquete aparte.
3. **`jumbojett/openid-connect-php`** — cliente OIDC genérico (descubrimiento, JWKS, validación de `id_token`).
4. **Escribir el flujo a mano** con Guzzle (ya presente) más `firebase/php-jwt`: URL de autorización, `state`, PKCE, canje del código, verificación del `id_token` contra el JWKS de Google. Unas 150 líneas.

No hay una quinta. Descartar la 4 no es automático: a diferencia de TOTP (`ADR-041 §1.1`, donde equivocarse es silencioso), el flujo de código de autorización de OIDC es corto y sus fallos son ruidosos. El argumento contra ella está en `§5`, y no es «es difícil».

---

## 2 · Comprobación de `CLAUDE.md §1`, con datos

Verificado el 2026-08-31.

| Criterio | **`laravel/socialite`** | `league/oauth2-client` (+ `-google`) | `jumbojett/openid-connect-php` |
|----------|-------------------------|--------------------------------------|--------------------------------|
| **Última *release*** | **v5.30.1 · 2026-08-24** (7 días) | 2.9.0 · 2025-11-25 (279 días) / `-google` 5.0.0 · 2026-03-23 | **v1.0.2 · 2024-09-13 (más de 23 meses)** |
| **Licencia** | **MIT** | MIT | **Apache-2.0** |
| **Repositorio** | `laravel/socialite`, rama `5.x`, **no archivado, no deshabilitado** | `thephpleague/oauth2-client`, no archivado | `jumbojett/OpenID-Connect-PHP`, no archivado |
| **Último *push*** | **2026-08-31** (hoy) | — | — |
| ***Releases* últimos 12 meses** | **14** | 1 | **0** |
| ***Commits* últimos 12 meses** | **45** | — | — |
| **Descargas** | **118.312.304 totales · 5.041.993/mes** | 3.518.122/mes | 495.754/mes |
| **Estrellas / *issues* abiertas** | 5.746 / **3** | 3.817 / 62 | 736 / **137** |
| **Marcado abandonado en Packagist** | No | No | No |
| **Avisos de seguridad (GitHub Advisory, Composer)** | **2, ambos de 2015 y ambos ya corregidos** (`GHSA-h97c-qp24-439v`, `GHSA-7fjv-25q9-2w88`, *state* inseguro/adivinable): rangos afectados **`< 2.0.9` y `< 2.0.10`**. Ninguna versión 5.x está afectada | — | — |
| **Compatibilidad declarada** | `php ^8.1`; `illuminate/contracts`, `illuminate/http`, `illuminate/support` **incluyen `^13.0`** | `php ^7.1\|^8.0` | `php >=7.4` |

**`socialite` pasa la comprobación con holgura y las otras dos no la pasan igual de bien.** `jumbojett/openid-connect-php` **falla «mantenimiento activo»**: 23 meses sin *release* y 137 *issues* abiertas. `league/oauth2-client` está viva pero publica una vez al año, tiene 62 *issues* abiertas y **parte la solución en dos paquetes de mantenedores distintos**, uno de los cuales (`league/oauth2-google`, 425 estrellas) es el que decide qué campos de Google se leen — justo la pieza de la que depende la nota de seguridad de `REQ-AUTH-002`.

Los dos avisos de `socialite` conviene decirlos en voz alta precisamente **para que nadie los vuelva a levantar en una revisión**: son de 2015, ambos sobre la generación del parámetro `state`, y ambos corregidos antes de la 2.1. Verificado en el código de `5.x` que hoy `state` se genera con `Str::random(40)` y se compara contra el guardado en sesión en `hasInvalidState()`.

Lo que **sí** hay que decir en contra de la elegida, entero:

- **Arrastra cuatro dependencias de ejecución para cubrir un caso de uso.** `require` de v5.30.1: `firebase/php-jwt ^6.4|^7.0`, `guzzlehttp/guzzle ^6.0|^7.0`, `league/oauth1-client ^1.11`, `phpseclib/phpseclib ^4.0`. De ellas `guzzle` ya está en `composer.lock`, así que el crecimiento real del árbol es de **tres paquetes nuevos**: `firebase/php-jwt`, `league/oauth1-client` y `phpseclib/phpseclib`.
- **Dos de esas tres son para OAuth1**, que este proyecto no va a usar nunca. `league/oauth1-client` existe para Twitter/X y similares, y arrastra `phpseclib` para la firma RSA-SHA1 de OAuth1. Es peso muerto: `composer.json` no ofrece forma de excluir dependencias de un paquete.
- **`phpseclib` saltó de `^3.0` a `^4.0` entre v5.30.0 (2026-08-13) y v5.30.1 (2026-08-24)**, en once días y en una *patch*. Un cambio de mayor de una dependencia criptográfica dentro de una versión de parche es un dato de gobierno del paquete que merece anotarse, aunque no cambie la decisión.

Es un coste real, y se acepta con los ojos abiertos. Lo que compra a cambio está en `§5`.

---

## 3 · Lo que decide de verdad: `email_verified` no es un campo

`REQ-AUTH-002` condiciona la fusión automática de cuentas a `email_verified = true`. Verificado leyendo `src/Two/GoogleProvider.php` y `src/Two/User.php` de la rama `5.x`, el estado real es peor que «hay que leerlo del array crudo». Hay **tres trampas**, y las tres desaparecen con el envoltorio:

**Trampa 1 · `mapUserToObject()` no lo mapea, y a la vez crea un alias.** El proveedor mapea `id`, `nickname`, `name`, `email`, `avatar` y `avatar_original`. `email_verified` se queda solo en el array crudo. Pero además el propio proveedor escribe, con este comentario en el código:

```
// Deprecated: Fields added to keep backwards compatibility in 4.0. These will be removed in 5.0
$user['verified_email'] = Arr::get($user, 'email_verified');
```

Es decir: en el array crudo hay **dos claves con el mismo significado**, `email_verified` (la de OIDC, la buena) y `verified_email` (copia heredada, marcada para eliminación). Quien lea la segunda escribe código que dejará de funcionar sin ruido, y **el fallo silencioso cae del lado inseguro**: la clave desaparece, el `?? false` se activa y todas las fusiones pasan a pedir confirmación... o, si alguien escribió `?? true` para «no molestar al usuario», ninguna la pide.

**Trampa 2 · el tipo del valor no está garantizado.** `getUserByToken()` tiene **dos caminos**: si el *token* parece un JWT (heurística `substr_count($token, '.') === 2 && strlen($token) > 100`) decodifica el `id_token`; si no, llama a `https://www.googleapis.com/oauth2/v3/userinfo`. El flujo normal de `REQ-AUTH-002` usa el segundo, donde `email_verified` llega como booleano JSON. Pero **el tipo depende del camino y del proveedor**, y en OIDC es habitual encontrarlo como la cadena `"true"`. Aquí está la trampa de verdad:

> `(bool) "false"` es **`true`** en PHP. Y `'false' ?? false` es `'false'`, que es *truthy*.

Una normalización descuidada convierte un correo **no verificado** en una fusión automática de cuenta. Ese es el escenario de apropiación completo: registro una cuenta Google con el correo de una jefa de estudios sin verificarlo, entro, y la plataforma me fusiona con su usuario, sus roles y su historial.

**Trampa 3 · la identidad estable es `sub`, no el correo.** El proveedor mapea `id` desde `sub`. Fusionar y luego reconocer al usuario por el correo es incorrecto: en Google Workspace un correo puede reasignarse a otra persona cuando alguien se va del centro. La identidad que persiste es `sub`.

**Las tres se arreglan de raíz con la misma medida**: el booleano se normaliza **en un solo fichero**, con regla estricta, y sale del envoltorio ya como `bool` de primera clase. Ninguna otra parte del código ve nunca el array crudo.

---

## 4 · Decisión

### 4.1 La dependencia

**Se aprueba `laravel/socialite`, restricción `^5.30`, versión mínima `v5.30.1`**, en `require` de `apps/api/composer.json`.

**No se aprueba ningún paquete de `socialiteproviders/*`.** `REQ-AUTH-002` es solo Google, y el proveedor de Google viene en el paquete base. Los proveedores de comunidad se evaluarán, si hacen falta, en `1.4b` y con su propio ADR.

### 4.2 El envoltorio (`RNF-MANT-007`)

Cuatro piezas, siguiendo el precedente exacto de `IpGeolocator`/`NullIpGeolocator` y de `TotpProvisioner`/`Google2FaTotpVerifier`:

| Pieza | Ruta | Contenido |
|-------|------|-----------|
| **Interfaz** | `app/Modules/Auth/Domain/ExternalIdentityProvider.php` | Los dos únicos verbos que `REQ-AUTH-002` necesita, en `§4.3` |
| **Objeto de valor** | `app/Modules/Auth/Domain/ExternalIdentity.php` | `final`, propiedades `readonly`. El resultado del *callback*, ya normalizado. **`emailVerified` es un `bool`, no un array** |
| **Excepción de dominio** | `app/Modules/Auth/Domain/ExternalIdentityException.php` + enum `ExternalIdentityFailure` | Tres motivos distinguibles, en `§4.4` |
| **Adaptador único** | `app/Modules/Auth/Infrastructure/SocialiteGoogleIdentityProvider.php` | El **único** fichero de todo `apps/api` autorizado a escribir `use Laravel\Socialite\...` |
| ***Binding*** | `app/Modules/Auth/Infrastructure/AuthServiceProvider::register()` | `$this->app->bind(ExternalIdentityProvider::class, SocialiteGoogleIdentityProvider::class);`, junto a los seis *bindings* que ya hay allí |

**Ninguna otra clase de `apps/api` —controlador, *job*, *listener*, *policy*, *seeder* o test— importa `Laravel\Socialite\*`.** Los tests de aplicación dependen de `ExternalIdentityProvider` y lo sustituyen por un doble; solo el test del adaptador conoce Socialite.

### 4.3 Forma de la interfaz

```php
namespace App\Modules\Auth\Domain;

interface ExternalIdentityProvider
{
    /**
     * Inicia el flujo: devuelve la URL de autorización del proveedor.
     *
     * Devuelve una cadena, NO una RedirectResponse (§4.5).
     * Tiene efecto de sesión: guarda `state` y, con PKCE, `code_verifier`.
     * El nombre lo dice a propósito — no es un getter puro.
     */
    public function beginAuthorization(): string;

    /**
     * Resuelve el callback y devuelve la identidad ya normalizada.
     *
     * @throws ExternalIdentityException
     */
    public function completeAuthorization(): ExternalIdentity;
}
```

```php
final class ExternalIdentity
{
    public function __construct(
        public readonly string $providerUserId,  // `sub`. La identidad estable, no el correo (§3, trampa 3)
        public readonly string $email,
        public readonly bool $emailVerified,     // bool de primera clase, normalizado en un solo sitio (§4.4)
        public readonly ?string $displayName,    // `name`
        public readonly ?string $givenName,      // `given_name`
        public readonly ?string $familyName,     // `family_name`, TAL CUAL lo da Google (§4.6)
        public readonly ?string $avatarUrl,      // `picture`
    ) {}
}
```

**Nada más.** No hay `token`, ni `refreshToken`, ni `approvedScopes`, ni el array crudo. `REQ-AUTH-002` no llama a ninguna API de Google después del login: solo identifica a una persona. Un `accessToken` guardado es un secreto de terceros que habría que cifrar, rotar, auditar y borrar; no se trae lo que no se necesita. Si algún requisito futuro necesita llamar a Google en nombre del usuario, será otro ADR y otra conversación sobre consentimiento (`INV-008`).

**Interfaz de un solo proveedor a propósito.** No lleva parámetro `string $provider` ni se generaliza a un registro de proveedores. Hoy hay uno. Cuando `1.4b` traiga varios —y serán OIDC por tenant y SAML, que tiene otra forma entera— decidirá entonces si añade un registro, y esta interfaz o se reutiliza o se queda como está sin estorbar. Una interfaz que la mitad de sus implementaciones no puede cumplir es peor que dos interfaces (`ADR-041 §1.4`); una interfaz generalizada por adelantado para un caso que aún no está especificado es el mismo error un paso antes.

### 4.4 Normalización de `emailVerified`: la regla exacta

Se escribe **una vez**, en `SocialiteGoogleIdentityProvider`, y la regla es de lista blanca, no de casting:

```
emailVerified = true  ⟺  el valor crudo de `email_verified` es exactamente
                          el booleano true o la cadena 'true'.
Cualquier otra cosa — false, 'false', '0', 0, null, cadena vacía,
ausencia de la clave — es false.
```

Prohibido: `(bool)`, `filter_var(..., FILTER_VALIDATE_BOOLEAN)` y cualquier comprobación de veracidad sobre el valor sin comparar el tipo. Prohibido leer `verified_email` (`§3`, trampa 1). **Fallo en cerrado**: la ausencia de la clave nunca produce `true`.

Esta regla necesita test propio con los ocho valores de entrada (`true`, `'true'`, `false`, `'false'`, `'0'`, `0`, `null`, clave ausente) referenciando `REQ-AUTH-002` (`INV-015`). No es un test de biblioteca: es el test de la nota de seguridad del requisito.

**Fallos y su motivo** — `Laravel\Socialite\Two\InvalidStateException` no sale nunca del adaptador. Se traduce a `ExternalIdentityException` con uno de tres motivos, porque el controlador tiene que hacer **tres cosas distintas**:

| Motivo | Cuándo | Qué debe hacer quien lo recibe |
|--------|--------|-------------------------------|
| `CONSENT_DENIED` | Google devuelve `error=access_denied` (la persona canceló) | Volver al login sin alarma. **No** es un incidente |
| `INVALID_STATE` | `state` no coincide o falta (`InvalidStateException`) | Señal de CSRF o de sesión perdida. Se audita y se rechaza |
| `PROVIDER_UNREACHABLE` | Fallo HTTP contra Google o respuesta ilegible | Transitorio. Mensaje de reintento |

Sin este enum, el controlador acaba capturando `\Exception` y decidiendo por el texto del mensaje, que es exactamente lo que no queremos que dependa de una biblioteca externa.

### 4.5 Por qué la interfaz devuelve una URL y no una `RedirectResponse`

`Socialite::driver('google')->redirect()` devuelve una `Illuminate\Http\RedirectResponse`. Devolver eso desde `Domain/` metería un tipo HTTP del framework en la capa de dominio, que hoy no tiene ninguno. Y hay una razón de producto además de una de higiene: **la SPA es un cliente más** (`INV-006`), y quien decide si la respuesta es un `302` o un JSON con la URL para que el navegador navegue es la capa HTTP, en `api.md`, no la biblioteca. Devolver la cadena deja las dos opciones abiertas; devolver la `RedirectResponse` cierra una y ata `Domain/` a Laravel.

### 4.6 Lo que el envoltorio NO hace, y es deliberado

**No parte apellidos.** Google entrega `family_name` como **una** cadena. `people` tiene `given_name`, `family_name_1` y `family_name_2` (migración `2026_08_18_100200_create_people_table.php`). El envoltorio entrega `familyName` tal cual y **no** aplica ninguna heurística de división. Partir por el primer espacio destroza «García de la Torre», «De la Fuente» o cualquier apellido compuesto, y lo hace en la creación de la persona, donde el error queda escrito. **Cómo se rellenan esas tres columnas desde estos campos es decisión de `docs/modulos/REQ-AUTH/datos.md`, no de este ADR**, y si acaba necesitando que la persona confirme sus apellidos, mejor eso que adivinarlos.

**No decide si se pide PKCE.** Se deja constancia de que `enablePKCE()` existe en `AbstractProvider` (`$usesPKCE`, `code_challenge`/`code_challenge_method` en la URL, `code_verifier` desde sesión en el canje) y de que el adaptador es el sitio donde se activaría, en una línea. Activarlo o no es decisión de la especificación de flujo.

---

## 5 · Motivo

**Socialite y no escribirlo a mano, y el motivo no es la dificultad.** El flujo de código de autorización se escribe en una tarde y sus fallos son ruidosos. Lo que decide es **el mantenimiento a tres años**: Google ha cambiado el endpoint de `userinfo`, ha deprecado Google+, ha cambiado la forma de los *claims* y hoy convive `email_verified` con `verified_email` precisamente por eso. Un cliente escrito a mano no es 150 líneas, son 150 líneas **más el trabajo de enterarse** cada vez que el proveedor cambia algo. Socialite publicó 14 versiones en doce meses; esas 14 son exactamente el trabajo que no hacemos. Con un solo desarrollador (`CLAUDE.md §2`), ese es el argumento decisivo, y es de coste de mantenimiento, no de capacidad.

**Socialite y no `league/oauth2-client`, porque la pieza crítica quedaría en el paquete menos vigilado.** La League publica una versión al año en el paquete base y delega el proveedor de Google en un paquete satélite de 425 estrellas. La nota de seguridad de `REQ-AUTH-002` depende de qué campos lee ese satélite. Socialite mantiene el proveedor de Google **en el mismo repositorio que el cliente**, con la misma cadencia y el mismo equipo, y ese repositorio es de primera parte del framework que ya usamos.

**Socialite y no `jumbojett/openid-connect-php`, porque está parada.** 23 meses sin *release*, 137 *issues* abiertas. Incumple «mantenimiento activo» de `CLAUDE.md §1`. Es el mismo criterio con el que `ADR-041 §2.1` rechazó `qrcode`, y se aplica igual aunque aquí el candidato sea menos popular.

**El envoltorio, y su forma concreta, porque es lo único que hace reversible una decisión cara de revertir.** Socialite trae tres paquetes que no necesitamos y uno de ellos cambió de mayor en una *patch*. Si eso se convierte en un problema, el coste de salir debe ser **un fichero**. Con `SocialiteGoogleIdentityProvider` como único punto de contacto, sustituir Socialite por `league/oauth2-client` o por 150 líneas de Guzzle es reescribir ese fichero y sus pruebas: ni los controladores, ni los *jobs*, ni el esquema, ni un solo test de aplicación se enteran. Sin el envoltorio, `Laravel\Socialite\Two\User` se filtra al controlador, al *listener* de auditoría y a los tests, y salir cuesta un refactor.

**Y `emailVerified` como `bool` en un objeto de valor, porque la alternativa no es «menos elegante»: es explotable.** `$user->user['email_verified'] ?? false` disperso funciona hasta el día que alguien lo cambia, y `(bool) 'false'` es `true`. Un booleano normalizado en un sitio, con lista blanca y un test de ocho casos, convierte la nota de seguridad de `REQ-AUTH-002` en algo que se puede verificar de un vistazo en una revisión de código.

---

## 6 · Consecuencias

**A favor**

- La nota de seguridad de `REQ-AUTH-002` deja de ser una advertencia en prosa y pasa a ser un `bool` con una regla escrita y un test. Se puede revisar; se puede romper y que se note.
- El envoltorio está nombrado, ubicado y con la firma fijada aquí: `implementer` no decide arquitectura mientras implementa (misma consecuencia buscada por `ADR-041`).
- `Domain/` sigue sin tipos HTTP del framework (`§4.5`).
- Ningún *token* de Google se persiste (`§4.3`), así que este paso **no** añade secretos de terceros que cifrar, rotar ni borrar.
- `1.4b` (`REQ-AUTH-004`) queda libre: esta decisión no le impone cliente, ni interfaz, ni forma.

**En contra, y se asume**

- **Tres paquetes de ejecución nuevos** (`firebase/php-jwt`, `league/oauth1-client`, `phpseclib/phpseclib`), **dos de ellos para OAuth1, que no usaremos jamás**. Composer no permite excluirlos. Es peso muerto en el árbol y en el escaneo de dependencias de cada PR (`CLAUDE.md §8`). Se acepta a cambio del mantenimiento de `§5`.
- **`phpseclib` pasó de `^3.0` a `^4.0` en una versión de parche** (v5.30.0 → v5.30.1, once días). Anotado como dato de gobierno del paquete: conviene leer las notas de versión de Socialite antes de subir de menor, no actualizar a ciegas.
- **El *callback* de OAuth depende de que la sesión sobreviva a una navegación de vuelta desde `accounts.google.com`.** Socialite guarda `state` (y `code_verifier` con PKCE) en la sesión. `config/session.php` tiene `same_site` = `lax`, que **sí** envía la cookie en una navegación de nivel superior por `GET` — el *callback* funciona. **Con `SESSION_SAME_SITE=strict` dejaría de funcionar**, y el síntoma sería `InvalidStateException` en el *callback*, sin ninguna pista que apunte a la cookie. Queda anotado aquí porque `CLAUDE.md §8` obliga a `SameSite` sin fijar el valor, y porque endurecerlo parece una mejora inocua.
- **Licencia**: MIT, compatible con distribución propietaria (el proyecto es «Todos los derechos reservados»), con la obligación de conservar el aviso de copyright. Con despliegue en contenedores el aviso viaja dentro de `vendor/`, así que se cumple por construcción; mismo razonamiento y misma anotación que `ADR-041`.
- **`ExternalIdentityProvider` sirve a un solo proveedor.** Si `1.4b` necesita varios habrá que introducir un registro. Es trabajo futuro consciente, preferido a generalizar hoy para un requisito que todavía no está especificado.

**Reversibilidad**: **alta, y es el argumento central del ADR.** Salir de Socialite es reescribir `SocialiteGoogleIdentityProvider.php` y sus pruebas. No hay migración de datos: el envoltorio no persiste nada — lo que se guarde de la identidad externa lo define `datos.md` en nuestros propios términos (`providerUserId`, `email`, `emailVerified`), sin ningún formato propietario de la biblioteca. Ni el esquema, ni la API pública, ni los datos persistidos llevan huella de Socialite.

---

## 7 · Alternativas descartadas y por qué

- **`league/oauth2-client` + `league/oauth2-google`**: viva, MIT, 3,5 M descargas/mes. Se descarta porque publica una vez al año en el paquete base (2.9.0, 2025-11-25), tiene 62 *issues* abiertas y **parte la solución entre dos mantenedores**, dejando el mapeo de *claims* de Google —del que depende la nota de seguridad de `REQ-AUTH-002`— en el paquete satélite y menos vigilado. **Es la sustituta natural** si Socialite se abandona.
- **`jumbojett/openid-connect-php`**: **rechazada por incumplimiento de `CLAUDE.md §1`** — última versión v1.0.2 de 2024-09-13, cero *releases* en doce meses, 137 *issues* abiertas. Licencia Apache-2.0 (compatible, pero con requisitos de aviso distintos de MIT, un motivo menor añadido).
- **Escribir el flujo OIDC a mano** con Guzzle + `firebase/php-jwt`: técnicamente viable y de coste inicial bajo, a diferencia de TOTP. Se descarta por **coste de mantenimiento a tres años con un solo desarrollador** (`§5`), no por dificultad. Es la opción a reconsiderar el día que el peso de `league/oauth1-client` + `phpseclib` moleste de verdad, y el envoltorio la deja a un fichero de distancia.
- **Cualquier paquete `socialiteproviders/*`**: innecesario, el proveedor de Google viene en el paquete base. Se evaluará en `1.4b` si hace falta, con su ADR.
- **Devolver `Laravel\Socialite\Two\User` desde la interfaz** (envoltorio nominal): sería `RNF-MANT-007` cumplido en la forma y no en el fondo. Deja el array crudo, las dos claves duplicadas y las tres trampas de `§3` circulando por controladores y tests, y no arregla nada. Es el mismo error que `ADR-041 §2.3` rechazó al descartar envolver un componente en otro componente.
- **Guardar el `accessToken`/`refreshToken` de Google**: descartado. Ningún requisito de `REQ-AUTH-002` llama a una API de Google después del login. Guardarlos añade secretos de terceros que cifrar, rotar, auditar y borrar, y una conversación sobre consentimiento (`INV-008`) que ahora mismo no tiene motivo.
- **Identificar al usuario federado por el correo en lugar de por `sub`**: descartado (`§3`, trampa 3). En Google Workspace un correo puede reasignarse a otra persona.
- **Interfaz genérica multiproveedor desde ya** (con `string $provider` o registro): descartada por generalidad especulativa. `1.4b` no está especificado y SAML tiene otra forma; se decide cuando exista el requisito.

---

## 8 · Preguntas abiertas

Ninguna que bloquee 1.4. Este ADR **no** abre preguntas de flujo: la fusión de cuentas, la vinculación y desvinculación desde el perfil, la confirmación explícita cuando `emailVerified` es `false`, y el modelo de datos de la identidad externa los cierra `docs/modulos/REQ-AUTH/*.md`.

Queda anotado para quien implemente, porque son los cuatro sitios donde esta integración se rompe en silencio:

1. **`(bool) 'false'` es `true` y `'false' ?? false` es *truthy*.** La normalización de `§4.4` es de lista blanca. Un `filter_var` con `FILTER_VALIDATE_BOOLEAN` parece correcto y devuelve `false` para `'false'`, pero también `false` para valores inesperados sin distinguirlos de una ausencia: la lista blanca explícita es lo que se revisa de un vistazo.
2. **No leer `verified_email`.** Es una copia marcada como *deprecated* en el propio código del proveedor, prevista para desaparecer. La clave correcta es `email_verified`.
3. **`getUserByToken()` tiene dos caminos** —`userinfo` o decodificación del `id_token`, según una heurística sobre la forma del *token*—, y el tipo de `email_verified` puede diferir entre ellos. Es el motivo por el que la normalización no puede asumir booleano.
4. **`SESSION_SAME_SITE=strict` rompe el *callback*** con un `InvalidStateException` que no señala hacia la cookie. Si alguien endurece esa variable «por seguridad», el login con Google deja de funcionar y el mensaje de error no lo explica.
