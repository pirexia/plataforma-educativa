# ADR-041 · Dependencias externas de MFA: TOTP en el backend y generación de QR en la SPA

**Estado**: ACEPTADA
**Fecha**: 2026-08-26
**Resuelve**: la comprobación formal de `CLAUDE.md §1` que `OPEN-AUTH-19` y `OPEN-AUTH-20` dejaron explícitamente pendiente (`docs/modulos/REQ-AUTH/funcional.md §C.14`, `§C.16`)
**Se apoya en**: `RNF-MANT-007` (toda dependencia externa tras interfaz propia), `funcional.md §C.9.2` (`MfaVerifier`), `§C.4.1` (alta TOTP), `§C.11` (accesibilidad de la pantalla de alta), `RN-AUTH-55`, `RN-AUTH-58`
**Afecta a**: paso **1.3** (`REQ-AUTH-003`). Es requisito previo de `implementer`
**No decide**: nada del método SMS (`OPEN-AUTH-18`: no hay proveedor) ni del método de correo (`1.3b`), que no necesitan dependencia externa nueva

---

## Contexto

`OPEN-AUTH-19` y `OPEN-AUTH-20` fueron aprobadas por el usuario el 2026-08-26 **a nivel de producto**: sí a una librería TOTP en el backend, sí a un generador de QR en la SPA, ambas envueltas tras interfaz propia. Las dos decisiones dejaron escrito que esa aprobación **no satisface** lo que exige `CLAUDE.md §1`:

> «Prohibido introducir una dependencia nueva sin justificarla y sin comprobar mantenimiento activo, licencia y frecuencia de releases. Toda dependencia externa se envuelve tras una interfaz propia (`RNF-MANT-007`).»

Este ADR es esa comprobación, hecha contra Packagist, npm y la API de GitHub el **2026-08-26**, no de memoria. Fija además el nombre y el sitio exactos de los envoltorios, para que `implementer` no tenga que decidirlos.

Hay una razón de fondo por la que esto no es un trámite: **son las dos primeras dependencias del proyecto que tocan material criptográfico de usuario**. Hasta hoy `composer.json` tiene tres paquetes de producción (el framework, `tinker`) y `package.json` once, ninguno con responsabilidad de seguridad. `RN-AUTH-58` (rechazar un paso de tiempo ya consumido) y `RN-AUTH-55` (el secreto sale del servidor una sola vez) dependen directamente de qué expone la librería elegida.

Estado de partida verificado: **no hay ninguna librería TOTP en `apps/api/composer.json` ni ningún generador de QR en `apps/web/package.json`**. Restricciones del entorno: `php: ^8.4`, `laravel/framework: ^13.17`, `vue: ^3.5`, `typescript: ~6.0`, `vite: ^8.2`.

---

## Parte 1 · Backend: librería TOTP

### 1.1 Opciones reales

Tres, no más: `pragmarx/google2fa`, `spomky-labs/otphp`, o escribir RFC 6238 a mano. La tercera la descartó ya `OPEN-AUTH-19` con su argumento (cuatro formas silenciosas de equivocarse: base32 mal decodificado, ventana mal calculada, comparación no constante, contador derivado del huso local); se ratifica y no se reabre.

### 1.2 Comprobación de `CLAUDE.md §1`, con datos

Verificado el 2026-08-26 contra `repo.packagist.org`, `packagist.org/packages/*.json` y `api.github.com`.

| Criterio | `pragmarx/google2fa` | `spomky-labs/otphp` |
|----------|----------------------|---------------------|
| **Última *release*** | **v9.1.0 · 2026-08-15** (11 días) | 11.5.0 · 2026-06-06 (81 días) |
| **Licencia exacta** | **MIT** (`LICENSE.md`: *«Copyright 2014-2018 Phil, Antonio Carlos Ribeiro and All Contributors»*) | **MIT** (`LICENSE`: *«Copyright (c) 2014-2016 Florent Morselli»*) |
| **Repositorio** | `antonioribeiro/google2fa`, rama `9.x`, **no archivado** | `Spomky-Labs/otphp`, rama `11.6.x`, **no archivado** |
| **Último *push*** | 2026-08-15 | 2026-08-01 |
| ***Commits* últimos 12 meses** | 36 | 46 |
| **Descargas** | **110.110.258 totales · 6.866.171/mes** | 53.214.027 totales · 1.648.992/mes |
| **Estrellas / *issues* abiertas** | 2.007 / **5** | 1.488 / 6 |
| **Marcado abandonado en Packagist** | No | No |
| **Requisitos de ejecución** | `php ^7.1\|^8.0` + **1 dependencia** (`paragonie/constant_time_encoding`) | `php >=8.1` + **3 dependencias** (`paragonie/constant_time_encoding`, `psr/clock`, `symfony/deprecation-contracts`) |
| **Avisos de seguridad (GitHub Advisory, ecosistema Composer)** | **0** | **2**, ambos del 2026-06-18, ambos corregidos en 11.4.3: `GHSA-g7m4-839x-ch6v` (alta) y `GHSA-2jx3-65f3-xr8r` (media) |
| ***Releases* de los últimos 6 años** | 8.0.0 (2020-04), 8.0.1 (2022-06), 8.0.2 (2024-07), 8.0.3 (2024-09), 9.0.0 (2025-09), **9.1.0 (2026-08)** | Serie 11.x continua, la más reciente 11.5.0 (2026-06) |
| **Matriz de CI** | PHP 7.4 → **8.6 beta**, PHPUnit 9 → 13, PHPStan 2, Psalm 6 | PHP 8.1+, Renovate al día |

**Ninguna de las dos falla la comprobación.** Las dos están vivas, las dos son MIT, las dos publican. Los dos avisos de `otphp` **no** son motivo de descarte —al contrario: que aparezcan, se corrijan y se publiquen es señal de escrutinio real— y además están en `Factory::loadFromProvisioningUri`, una ruta de código que este proyecto no usaría nunca (nosotros **generamos** URIs `otpauth`, no analizamos las de terceros). Que `google2fa` tenga cero avisos es un dato ambiguo, no una victoria: también puede significar menos escrutinio.

Un dato que sí hay que decir en voz alta, porque no favorece a la elegida: **`google2fa` tuvo dos huecos de dos años entre *releases*** (2022-06 → 2024-07 y 2020-04 → 2022-06) y **un solo mantenedor** (`AntonioCarlosRibeiro`). El *bus factor* es 1. Se compensa —no se ignora— en `§1.5`.

### 1.3 El desempate no es la popularidad: es `RN-AUTH-58`

`RN-AUTH-58` obliga a **rechazar un paso de tiempo ya consumido** por ese factor, y `datos.md §C.2` guarda para ello una columna `last_used_step`. Sin esa regla, un código capturado sigue valiendo hasta 90 segundos. Es la única regla de este paso que depende de la forma de la API de la librería:

| | Firma relevante | Qué permite |
|---|---|---|
| **`google2fa`** | `verifyKeyNewer($secret, $key, $oldTimestamp, $window = null, $timestamp = null): int\|false` | Devuelve **el paso de tiempo concreto que validó**. Se escribe tal cual en `last_used_step` y se vuelve a pasar como `$oldTimestamp` en la verificación siguiente. `RN-AUTH-58` es una llamada |
| **`otphp`** | `OTPInterface::verify(string $otp, ?int $input = null, ?int $window = null): bool` | Devuelve **solo `true`/`false`**. No hay forma de saber qué paso validó |

Con `otphp`, implementar `RN-AUTH-58` obliga a recorrer la ventana llamando a `at($step)` paso a paso y comparar nosotros — es decir, **a reescribir a mano exactamente la aritmética de ventana que `OPEN-AUTH-19` decidió no escribir**. La dependencia dejaría de cubrir el riesgo por el que se introduce.

Verificado también, leyendo `src/Google2FA.php` de la v9.1.0:

- `findValidOTP()` compara con **`hash_equals()`** — tiempo constante, como exige `RN-AUTH-58`.
- Los parámetros `$secret` y `$key` llevan **`#[\SensitiveParameter]`**, así que no aparecen en trazas de excepción ni en volcados de pila. Es exactamente la propiedad que `RN-AUTH-55` pide («ningún *log*»), y viene de serie.
- `getQRCodeUrl($company, $holder, $secret)` construye la URI `otpauth://totp/...` con `algorithm`, `digits` y `period` explícitos. Cubre `§C.4.1` punto 4 sin escribir nada.
- `generateSecretKey($length = 32)` mide `$length` **en caracteres base32, no en bytes**: 32 caracteres = 20 bytes, que es justo lo que pide `§C.4.1` punto 3. Es el sitio más fácil de equivocarse de toda la integración.

### 1.4 Decisión (backend)

**Se aprueba `pragmarx/google2fa`, restricción `^9.1`, versión mínima `v9.1.0`**, en `require` de `apps/api/composer.json`.

**Se rechazan expresamente los dos paquetes hermanos**:

- **`pragmarx/google2fa-laravel`** (vivo, 834.488 descargas/mes, no abandonado): aporta una fachada y un *service provider* que registran su propia configuración y su propio *middleware*. Nosotros hacemos el *binding* en `AuthServiceProvider`, igual que con `IpGeolocator`, y el login en dos pasos de `§C.4.4` no se parece a su *middleware*. Además arrastra `pragmarx/google2fa-qrcode` como dependencia obligatoria.
- **`pragmarx/google2fa-qrcode`**: renderiza imágenes QR en PHP. `§C.4.1` punto 4 es explícito —*«no devuelve una imagen»*—, así que sería una dependencia para una funcionalidad que la especificación prohíbe usar.

**Envoltorio** (`RNF-MANT-007`), siguiendo el precedente exacto de `IpGeolocator` / `NullIpGeolocator`:

| Pieza | Ruta | Contenido |
|-------|------|-----------|
| Interfaz de verificación | `app/Modules/Auth/Domain/MfaVerifier.php` | La que fija `funcional.md §C.9.2`. Un método de verificación por método de MFA. **Su firma no se toca aquí**: es el punto de extensión de SMS y WebAuthn |
| Interfaz de alta TOTP | `app/Modules/Auth/Domain/TotpProvisioner.php` | Generar el secreto base32 y construir la URI `otpauth://`. **Interfaz aparte, y a propósito**: un verificador de SMS o de correo no tiene secreto ni URI, y meter esos dos métodos en `MfaVerifier` obligaría a la mitad de sus implementaciones a lanzar excepciones. Una interfaz cuyos métodos la mitad de sus implementaciones no puede cumplir es peor que dos interfaces |
| Adaptador único | `app/Modules/Auth/Infrastructure/Google2FaTotpVerifier.php` | Implementa **las dos**. Es el **único** fichero de todo `apps/api` autorizado a escribir `use PragmaRX\Google2FA\...` |
| *Binding* | `app/Modules/Auth/Infrastructure/AuthServiceProvider.php` | `$this->app->bind(...)`, como la línea 43 ya hace con `IpGeolocator` |

---

## Parte 2 · Frontend: generación de QR

### 2.1 El candidato obvio no pasa la comprobación

`qrcode` (**node-qrcode**) es lo que cualquiera propondría: 23.369.050 descargas/semana, 8.160 estrellas, MIT. Verificado el 2026-08-26:

| Dato | Valor |
|------|-------|
| Última versión | **1.5.4 · 2024-08-05** (casi **dos años**) |
| Último *push* al repositorio | **2024-08-23** |
| *Issues* abiertas | **125** |
| Dependencias de ejecución | `pngjs ^5`, `dijkstrajs ^1` y **`yargs ^15`** — una librería de argumentos de línea de órdenes cuya rama 15 es de 2020, presente **solo** para la CLI del paquete, que en la SPA no se usa jamás |
| Tipos TypeScript | **No los trae.** Exige además `@types/qrcode` (DefinitelyTyped, 1.5.6, 2025-10-24), una **segunda** dependencia mantenida por gente distinta de la del paquete |

**`qrcode` se rechaza: incumple «mantenimiento activo» de `CLAUDE.md §1`.** Dos años sin *release* y sin un solo *commit*, con 125 *issues* abiertas, es un paquete parado. Los 23 millones de descargas semanales son inercia de otros paquetes que lo arrastran, no una señal de que alguien lo esté cuidando. Y traer `yargs@15` a un *bundle* de navegador para dibujar un cuadrado es exactamente la clase de coste que la regla existe para detectar.

Este es el motivo por el que este ADR no es un trámite: la opción que la especificación citaba primero como «candidata típica» es la que falla.

### 2.2 Las dos que sí pasan

| Criterio | **`uqr`** | `qrcode.vue` |
|----------|-----------|--------------|
| Última versión | 0.1.3 · 2026-04-03 | 3.10.0 · 2026-06-13 |
| Licencia | **MIT** | **MIT** |
| Repositorio | `unjs/uqr` (organización **unjs**), no archivado | `scopewu/qrcode.vue`, no archivado |
| Último *push* | 2026-04-03 | **2026-08-18** |
| Descargas/semana | **4.112.476** | 291.372 |
| Estrellas / *issues* abiertas | 755 / **0** (7 *issues* en toda su historia) | 825 / **0** |
| Dependencias de ejecución | **Ninguna** | **Ninguna** |
| Tipos TypeScript | **Nativos**, escritos en el propio paquete (`.d.mts` + `.d.ts`) | Nativos (`./dist/index.d.ts`) |
| Tamaño sin comprimir | **79.278 B** (paquete entero, ESM tree-shakable) | 270.809 B (incluye CJS + UMD + *sourcemaps*) |
| Qué entrega | Un **codificador**: `encode(data, opts)` → `{ version, size, maskPattern, data: boolean[][], types }` | Un **componente Vue** con `render-as` `canvas`\|`svg`, degradados, e incrustación de logotipo |

Las dos están mantenidas y las dos son MIT. La elección no la decide el mantenimiento: la decide **qué queda dentro de la dependencia y qué queda en nuestras manos**.

### 2.3 Por qué el codificador y no el componente

Cuatro razones, en orden de peso:

1. **`§C.11` impone requisitos sobre el SVG concreto, no sobre «un QR».** La pantalla de alta debe llevar *«alternativa textual que **no** contiene el secreto»* —porque un `alt` con el secreto lo mete en el árbol de accesibilidad y en cualquier captura de lector de pantalla— y debe funcionar en los cuatro idiomas (`INV-009`) y con el color del centro (`PublicAuthShell`). Con `encode()` devolviendo `boolean[][]`, el `<svg role="img" :aria-label="...">` lo escribimos nosotros en la plantilla, con `currentColor` y el texto que venga de `vue-i18n`. Con `qrcode.vue` esas decisiones viven dentro de la dependencia, que expone `background`/`foreground` como cadenas y ningún control de ARIA.
2. **Envolver un componente en otro componente da un envoltorio nominal.** `OPEN-AUTH-20` aprobó *«librería QR envuelta tras un componente propio»*. Si la librería ya es el componente, nuestro «envoltorio» es un reenvío de *props*: `RNF-MANT-007` quedaría cumplido en la forma y no en el fondo, y el día que haya que cambiar de librería habría que rehacer el marcado igual.
3. **`CLAUDE.md §1` prohíbe mezclar librerías de componentes de interfaz**, con shadcn-vue como sistema de diseño único. `qrcode.vue` es un componente Vue de terceros; discutir si «cuenta» como librería de componentes es tiempo perdido cuando existe una opción que no plantea la pregunta. `uqr` no es interfaz: es un codificador que devuelve una matriz de booleanos.
4. **La superficie que sobra es superficie que perjudica.** Los degradados y el logotipo incrustado con `excavate` de `qrcode.vue` degradan la fiabilidad de lectura; un QR de TOTP se escanea una vez, en un móvil, a veces con mala luz, y lo único que importa es que se lea a la primera.

**El argumento en contra, dicho entero**: `uqr` está en **0.1.3**, es decir, sin garantía de estabilidad de semver, y su actividad real es baja — `0.1.2` es de 2023-08-16 y `0.1.3` de 2026-04-03, con dos *commits* de fondo en tres años. Frente a eso: ISO/IEC 18004 es una especificación **congelada**; un codificador de QR sin dependencias no tiene casi motivos para cambiar; y cuando apareció un fallo real, se corrigió y se publicó (`fix(svg): escape color attributes in renderSVG to prevent SVG/XML`, 2026-04-03).

**Ese fallo concreto refuerza la decisión en vez de debilitarla**: estaba en `renderSVG()`, la función que devuelve *marcado como cadena*. Nosotros **no usamos `renderSVG()`**. Usamos `encode()` y pintamos la plantilla con Vue, así que no hay `v-html`, no hay marcado interpolado y esa clase de fallo no nos alcanza aunque vuelva.

### 2.4 Decisión (frontend)

**Se aprueba `uqr`, restricción `^0.1.3`, versión mínima `0.1.3`**, en `dependencies` de `apps/web/package.json`. **Se rechaza `qrcode` (node-qrcode) por mantenimiento parado**, y con él `@types/qrcode`.

**Envoltorio** (`RNF-MANT-007`, `OPEN-AUTH-20`):

| Pieza | Ruta | Contrato |
|-------|------|----------|
| Componente propio | `apps/web/src/components/QrCode.vue` | `props: { value: string; label: string; moduleSize?: number }`. `label` es texto **ya traducido** para `aria-label`, y **nunca** contiene el secreto (`§C.11`). Renderiza `<svg role="img">` a partir de `encode(value).data`, con `fill="currentColor"`, sin `v-html` y sin `<canvas>` |
| Aislamiento | — | `uqr` se importa **solo** en ese fichero. Conviene fijarlo con una regla `no-restricted-imports` en `eslint.config.js` para que no sea una convención que se olvida |

**Ubicación**: `src/components/`, **no** `src/components/ui/`, que está reservado a las primitivas generadas por shadcn-vue (`button`, `input`, `label`), y **no** `src/modules/auth/components/`, porque dibujar un QR no es lógica de autenticación y el día que otro módulo lo necesite importarlo desde `modules/auth` violaría `INV-007`.

---

## Motivo

**Backend — `google2fa` y no `otphp`, y el motivo no es que sea más popular.** `otphp` tiene mejor higiene de dependencias en un sentido (`psr/clock` permite inyectar el reloj en los tests) y peor en otro (tres dependencias de ejecución frente a una). Lo que decide es que **`verifyKeyNewer()` devuelve el paso de tiempo que validó y `verify()` de `otphp` solo devuelve un booleano**. Con `otphp` habría que reimplementar el recorrido de la ventana para poder escribir `last_used_step`, y eso deja `RN-AUTH-58` —la regla que impide que un código capturado valga 90 segundos— apoyada en código nuestro sin revisar por nadie, que es precisamente lo que `OPEN-AUTH-19` quería evitar al traer una librería. A eso se suma que `#[\SensitiveParameter]` en los argumentos del secreto da `RN-AUTH-55` («ningún *log* lo contiene») sin escribir una línea.

**Frontend — `uqr` y no `qrcode`, porque `qrcode` está parado.** Dos años sin *commits*, 125 *issues* abiertas y `yargs@15` en el árbol de ejecución no pasan «mantenimiento activo». Y `uqr` y no `qrcode.vue` porque **`§C.11` es un requisito sobre el SVG que se emite**, no sobre la existencia de un QR: la accesibilidad WCAG 2.2 AA, la alternativa textual sin secreto, los cuatro idiomas y el color del centro se resuelven escribiendo doce líneas de plantilla sobre una matriz de booleanos, y no se resuelven pasando *props* a un componente ajeno.

**Las dos elecciones comparten la misma lógica: se prefiere la dependencia que hace *menos*.** Una librería que devuelve un dato (el paso de tiempo validado; la matriz de módulos) deja la decisión en nuestro lado del envoltorio. Una que devuelve un resultado terminado (un booleano; un componente pintado) se la queda. Con `CLAUDE.md §0` y la prioridad de lo reversible sobre lo óptimo, la primera forma gana siempre que el coste añadido sea pequeño — y aquí lo es: una llamada y una plantilla.

---

## Consecuencias

**A favor**

- `RN-AUTH-58` (rechazo del paso ya consumido) y `RN-AUTH-55` (el secreto no aparece en trazas) quedan cubiertos por la librería, no por código propio.
- El árbol de dependencias crece en **un** paquete de ejecución en cada capa (`paragonie/constant_time_encoding` es dependencia transitiva de `google2fa`; `uqr` no tiene ninguna). Ningún `yargs`, ningún `pngjs`, ningún paquete de tipos de terceros.
- Los dos envoltorios están nombrados y ubicados aquí, así que `implementer` no decide arquitectura mientras implementa.
- `MfaVerifier` conserva **exactamente** la firma que `funcional.md §C.9.2` le dio. El día que exista proveedor de SMS se añade una implementación más, sin tocar esta decisión.

**En contra, y se asume**

- **`pragmarx/google2fa` tiene un solo mantenedor y un historial con dos huecos de dos años.** Si mañana se abandona, el proyecto se queda con una dependencia criptográfica sin dueño. Mitigación real, no retórica: todo el contacto con la librería vive en `Google2FaTotpVerifier.php`; sustituirla por `otphp` (o por una implementación propia con la ventana escrita a mano y probada con los vectores del RFC 6238) es reescribir **un fichero** y sus pruebas, sin migración de datos — el secreto almacenado es base32 estándar y no tiene formato propietario de la librería.
- **`uqr` está en 0.1.x.** Un `0.2.0` puede romper la firma de `encode()` sin previo aviso. Se fija `^0.1.3` (que en npm bloquea el menor: `>=0.1.3 <0.2.0`), y `package-lock.json` clava la versión exacta. La superficie que usamos es **una función y un campo** (`encode().data`).
- **Licencia**: el proyecto es propietario (`LICENSE`: *«Todos los derechos reservados»*). MIT es compatible con distribución propietaria, pero **obliga a conservar el aviso de copyright**. Con despliegue en contenedores el aviso viaja dentro de `vendor/` y `node_modules/`, así que se cumple por construcción; queda anotado para el día que haya un artefacto de distribución que los excluya.
- **Dos dependencias más que escanear en cada PR** (`CLAUDE.md §8`). Es el coste esperado, no un efecto secundario.
- `google2fa` declara `php ^7.1|^8.0`, una restricción laxa que no menciona 8.4 explícitamente. Verificado que su CI prueba de PHP 7.4 a **8.6 beta**, con PHPUnit hasta 13: la restricción es antigua, la cobertura real no.

**Reversibilidad**: **alta en las dos capas, y es el argumento central del ADR.** Backend: un fichero (`Google2FaTotpVerifier.php`), sin datos que migrar. Frontend: un fichero (`QrCode.vue`), sin estado ninguno — un QR se dibuja y se olvida. Ninguna de las dos decisiones deja huella en el esquema, en la API pública ni en datos persistidos.

---

## Alternativas descartadas y por qué

- **`spomky-labs/otphp`**: pasa la comprobación de `CLAUDE.md §1` sin problemas (MIT, 11.5.0 de 2026-06-06, 1,6 M descargas/mes, mantenido). Se descarta porque `verify()` devuelve un booleano y no el paso de tiempo validado, lo que obliga a reimplementar el recorrido de la ventana para cumplir `RN-AUTH-58`; y porque trae tres dependencias de ejecución en vez de una. **Es la sustituta natural** si `google2fa` se abandona.
- **Escribir RFC 6238 a mano**: descartada ya por `OPEN-AUTH-19` con su argumento; se ratifica. Cuesta poco escribirla y mucho descubrir que estaba mal.
- **`pragmarx/google2fa-laravel`**: registra fachada, configuración y *middleware* propios que no encajan con el login en dos pasos de `§C.4.4`, y arrastra `google2fa-qrcode` obligatoriamente. El *binding* propio en `AuthServiceProvider` cuesta una línea.
- **`pragmarx/google2fa-qrcode` o cualquier renderizador de QR en PHP**: `§C.4.1` punto 4 dice literalmente que la respuesta *«no devuelve una imagen»*. Sería una dependencia para algo que la especificación prohíbe.
- **`qrcode` (node-qrcode) + `@types/qrcode`**: **rechazada por incumplimiento de `CLAUDE.md §1`** — sin *release* desde 2024-08-05, sin *commits* desde 2024-08-23, 125 *issues* abiertas, `yargs@15` en ejecución y sin tipos propios.
- **`qrcode.vue`**: mantenida y limpia (cero dependencias, tipos propios, *releases* mensuales), pero es un componente de interfaz de terceros que se queda dentro las decisiones de marcado que `§C.11` nos obliga a controlar (ARIA, alternativa textual sin secreto, color del centro), y envolverlo daría un envoltorio nominal. **Es la sustituta natural** si `uqr` se abandona y no aparece otro codificador.
- **`qr-code-styling`**: 515 KB sin comprimir, con dependencia de `qrcode-generator`, orientada a QR decorativos con logotipo y degradados. Todo lo que aporta perjudica la lectura de un QR de TOTP.
- **No dibujar QR y entregar solo la clave en base32**: la evaluó y descartó `OPEN-AUTH-20` (transcribir 32 caracteres a mano en un móvil pierde a la mitad de quienes iban a activar MFA voluntariamente). Se ratifica. Nótese que la clave en texto **se entrega igualmente**, siempre y visible: es requisito de `§C.4.1` punto 4 y de `§C.11`, no un plan alternativo.

---

## Preguntas abiertas

Ninguna que bloquee 1.3.

Queda anotado para quien implemente, porque son los tres sitios donde esta integración se rompe en silencio:

1. **`generateSecretKey($length = 32)` mide caracteres base32, no bytes.** 32 caracteres = 20 bytes = `§C.4.1` punto 3. Pasar `20` daría un secreto de 12,5 bytes, por debajo de lo que recomienda el RFC 4226 y sin que nada falle visiblemente.
2. **`verifyKeyNewer()` devuelve `int|false`, no `bool`.** Comprobarlo con `if (!$resultado)` es correcto; usarlo con `===` contra `true` **nunca** acierta, y hacer `if ($resultado)` sin guardar el entero pierde el paso de tiempo que hay que escribir en `last_used_step`. La única comparación válida es `false === $resultado`.
3. **`uqr.renderSVG()` no se usa nunca** (`§2.3`). El componente parte de `encode(value).data` y construye el `<svg>` en la plantilla de Vue.
