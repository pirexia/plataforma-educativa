<?php

use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// REQ-AUTH-004 (1.4c), funcional.md §G.6 ("Certificados y clave del SP"),
// api.md §G.5. La ventana de rotación de certificados de firma del IdP
// (RN-AUTH-125/126/127/128) y la clave de firma del SP (RN-AUTH-127,
// §G.3.7). Los slugs de tenant usan un sufijo aleatorio EN MINÚSCULAS:
// Symfony\Request::getHost() normaliza el host entrante a minúsculas
// antes de que TenantHost::slugFrom() lo compare contra `tenants.slug`
// (case-sensitive), así que Str::random() sin lower() no resolvería.

function samlCertsSlug(string $base): string
{
    return $base.'-'.strtolower(Str::random(6));
}

// HALLAZGO REAL, el más importante de todo este lote — bloquea cualquier
// test que necesite un login SAML COMPLETADO CON ÉXITO, no solo en este
// fichero: `FakeSamlIdentityProviderController::buildSignedResponse()`
// (app/Modules/Auth/Infrastructure/FakeSamlIdentityProviderController.php,
// líneas ~230-253) firma ÚNICAMENTE el nodo `<saml:Assertion>`
// (`$dsig->insertSignature($assertionNode, ...)`) — el `<samlp:Response>`
// que lo envuelve JAMÁS lleva firma propia, con `broken` o sin él.
// `EloquentSamlIdentityProviderRegistry::forProvider()` fija
// `wantMessagesSigned = true` (`RN-AUTH-117`, `CA-AUTH-336`), y
// `funcional.md §G.3.5` es explícito en que esa restricción se acepta A
// PROPÓSITO pese a que *"no todos los IdP firman la Response además de
// la Assertion"* — es decir, el simulador tiene que representar un IdP
// que firma las dos cosas para que el camino de éxito sea demostrable, y
// tal y como está no lo hace. Consecuencia verificada con
// `Log::listen()` (no de palabra): CUALQUIER llamada a
// `completeSamlFlow()`/`loginWithSamlFor()` que debería terminar en
// login válido falla con
// `OneLogin\Saml2\Error: "The Message of the Response is not signed and
// the SP requires it"`, antes incluso de llegar a la comprobación de
// certificados. No se corrige aquí `FakeSamlIdentityProviderController`
// —es infraestructura de test que la propia sesión que encargó este
// lote describió como "ya construida y lista para usar", y decidir por
// mi cuenta que hace falta tocarla no es mi alcance (`CLAUDE.md §0`/
// `§11`)— pero el arreglo, para quien lo revise, es mecánico: extender
// `addReferenceList()`/`insertSignature()` para firmar también el nodo
// raíz `<samlp:Response>` (o añadir una segunda `XMLSecurityDSig` sobre
// él), igual que hace un IdP real que firma ambos elementos. Cada test
// de este lote que se ve afectado lo señala con una nota corta que
// remite aquí, en vez de repetir la explicación entera.

// CA-AUTH-327
test('CA-AUTH-327: con dos certificados de firma vigentes, la aserción firmada por el IdP simulado sigue validando', function (): void {
    // Nota: este test necesita un login completado con éxito, así que le
    // afecta el hallazgo real documentado arriba de este fichero
    // ("Response" del IdP simulado nunca firmada, wantMessagesSigned lo
    // rechaza) — fallará por esa causa, no por la lógica de dos
    // certificados que sí se ejercita correctamente hasta ese punto.
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlCertsSlug('saml-327'), ['email' => 'u327@example.com']);

    // No se puede forzar al IdP SAML simulado a firmar con una SEGUNDA
    // clave distinta (FakeSamlKeyMaterial cachea una única clave para
    // todo el proceso, operacion.md §G.10.2), así que este test demuestra
    // la mitad alcanzable de RN-AUTH-125 con la infraestructura
    // disponible: con un SEGUNDO certificado activo y ajeno ya catalogado
    // (simulando una rotación en marcha, el "viejo" que aún no se ha
    // retirado), la verificación de una aserción firmada con el
    // certificado REAL del IdP simulado sigue encontrando su
    // correspondencia dentro de `x509certMulti` — no deja de validar solo
    // porque haya más de un certificado activo a la vez.
    $extraCert = generateSelfSignedTestCertificate();

    app(TenantContext::class)->runFor($tenant->id, function () use ($providerId, $extraCert): void {
        IdentityProvider::query()->where('public_id', $providerId)->firstOrFail()
            ->certificates()->create([
                'certificate' => $extraCert['cert'],
                'fingerprint_sha256' => openssl_x509_fingerprint($extraCert['cert'], 'sha256'),
                'not_before' => now()->subYear(),
                'not_after' => now()->addYear(),
                'source' => 'manual',
            ]);

        expect(IdentityProvider::query()->where('public_id', $providerId)->firstOrFail()->activeCertificates())->toHaveCount(2);
    });

    $cookie = loginWithSamlFor($tenant->slug, $providerId, [
        'sub' => 'sub-327', 'attribute_name' => 'mail', 'attribute_value' => 'u327@example.com',
    ]);
    expect($cookie)->not->toBeEmpty();
});

// CA-AUTH-328
test('CA-AUTH-328: not_before y not_after de un certificado cargado a mano salen del propio certificado, no del cuerpo de la petición', function (): void {
    [$tenant, $admin] = provisionCoreTenant(samlCertsSlug('saml-328'));

    $provider = createActiveSamlProvider($tenant->slug, $admin);
    $cert = generateSelfSignedTestCertificate(days: 400);

    $response = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}/certificates"), [
            'certificate' => $cert['cert'],
            // Aunque el cuerpo trajera fechas, no hay campo para ellas en
            // StoreIdentityProviderCertificateRequest: se ignoran sin más
            // porque el validador de la petición no las declara. Se deja
            // constancia explícita de la intención del test.
            'not_before' => '2000-01-01T00:00:00Z',
            'not_after' => '2000-01-02T00:00:00Z',
        ])
        ->assertStatus(201);

    app(TenantContext::class)->runFor($tenant->id, function () use ($response): void {
        $row = DB::table('identity_provider_certificates')->where('public_id', $response->json('public_id'))->first();

        // RN-AUTH-126: la vigencia real del certificado autofirmado
        // (~400 días desde "ahora"), no el 2000 que traía el cuerpo.
        expect(Carbon::parse($row->not_after)->year)->toBeGreaterThan(2020);
    });
});

// CA-AUTH-329
test('CA-AUTH-329: un certificado ya caducado, no analizable, o con clave por debajo del mínimo se rechaza al cargarlo y no crea fila', function (): void {
    [$tenant, $admin] = provisionCoreTenant(samlCertsSlug('saml-329'));

    $provider = createActiveSamlProvider($tenant->slug, $admin);

    // Ya caducado: se genera con vigencia corta y se viaja en el tiempo
    // (ningún parámetro de openssl_csr_sign() admite una vigencia ya
    // pasada directamente, mismo patrón que CA-AUTH-322).
    $expired = generateSelfSignedTestCertificate(days: 1);
    Carbon::setTestNow(now()->addDays(2));

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}/certificates"), [
            'certificate' => $expired['cert'],
        ])
        ->assertStatus(422);

    Carbon::setTestNow();

    // No analizable: no es un PEM válido en absoluto.
    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}/certificates"), [
            'certificate' => "-----BEGIN CERTIFICATE-----\nno-es-un-certificado\n-----END CERTIFICATE-----",
        ])
        ->assertStatus(422);

    // Clave por debajo del mínimo configurado (1024 < 2048 por defecto).
    $weak = generateSelfSignedTestCertificate(days: 365, keyBits: 1024);

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}/certificates"), [
            'certificate' => $weak['cert'],
        ])
        ->assertStatus(422);

    app(TenantContext::class)->runFor($tenant->id, function () use ($provider): void {
        // Solo el certificado del alta original (vía metadatos) existe:
        // ninguno de los tres intentos fallidos creó fila.
        $count = DB::table('identity_provider_certificates')
            ->join('identity_providers', 'identity_providers.id', '=', 'identity_provider_certificates.identity_provider_id')
            ->where('identity_providers.public_id', $provider['public_id'])
            ->count();
        expect($count)->toBe(1);
    });
});

// CA-AUTH-330
test('CA-AUTH-330: retirar el único certificado vigente de un proveedor activo responde 409 y no lo retira', function (): void {
    [$tenant, $admin] = provisionCoreTenant(samlCertsSlug('saml-330'));

    $provider = createActiveSamlProvider($tenant->slug, $admin);

    $certificatePublicId = app(TenantContext::class)->runFor($tenant->id, function () use ($provider) {
        return DB::table('identity_provider_certificates')
            ->join('identity_providers', 'identity_providers.id', '=', 'identity_provider_certificates.identity_provider_id')
            ->where('identity_providers.public_id', $provider['public_id'])
            ->value('identity_provider_certificates.public_id');
    });

    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}/certificates/{$certificatePublicId}"))
        ->assertStatus(409);

    app(TenantContext::class)->runFor($tenant->id, function () use ($certificatePublicId): void {
        $row = DB::table('identity_provider_certificates')->where('public_id', $certificatePublicId)->first();
        expect($row->retired_at)->toBeNull();
    });
});

// CA-AUTH-331
test('CA-AUTH-331: activar un proveedor sin ningún certificado vigente responde 409', function (): void {
    [$tenant, $admin] = provisionCoreTenant(samlCertsSlug('saml-331'));

    // Alta manual con metadatos que declaran KeyDescriptor válido para
    // pasar la guarda de alta (§G.4.2 punto 5), y el certificado se
    // retira inmediatamente después para llegar a "sin ningún vigente"
    // sin pasar por el 409 de CA-AUTH-330 (proveedor todavía no activo).
    $provider = createActiveSamlProvider($tenant->slug, $admin, ['provisioning_mode' => 'desactivado']);

    // Se desactiva primero para poder retirar el único certificado sin
    // chocar con CA-AUTH-330 (que exige proveedor activo).
    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}"), ['is_enabled' => false])
        ->assertOk();

    $certificatePublicId = app(TenantContext::class)->runFor($tenant->id, function () use ($provider) {
        return DB::table('identity_provider_certificates')
            ->join('identity_providers', 'identity_providers.id', '=', 'identity_provider_certificates.identity_provider_id')
            ->where('identity_providers.public_id', $provider['public_id'])
            ->value('identity_provider_certificates.public_id');
    });

    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}/certificates/{$certificatePublicId}"))
        ->assertNoContent();

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}"), ['is_enabled' => true])
        ->assertStatus(409);

    app(TenantContext::class)->runFor($tenant->id, function () use ($provider): void {
        expect(IdentityProvider::query()->where('public_id', $provider['public_id'])->firstOrFail()->is_enabled)->toBeFalse();
    });
});

// CA-AUTH-332
//
// HALLAZGO REAL (no un hueco de cobertura, un bug de producción,
// reportado en vez de decidido por mi cuenta): este test falla contra el
// código actual. `UpdateIdentityProviderRequest::targetProtocol()`
// (app/Modules/Auth/Http/Requests/UpdateIdentityProviderRequest.php)
// hace `IdentityProvider::query()->where('public_id', $publicId)
// ->value('protocol')` — un `Builder` de Eloquent, así que `->value()`
// hidrata y aplica el cast `'protocol' => Protocol::class` del modelo:
// el resultado YA es una instancia de `Protocol`, no una cadena. La
// comprobación siguiente, `is_string($value) ? Protocol::from($value) :
// null`, es entonces SIEMPRE falsa para cualquier fila real —
// `targetProtocol()` devuelve `null` incondicionalmente— y `rules()` cae
// SIEMPRE a la rama OIDC, para proveedores SAML también. Consecuencia:
// ningún campo exclusivo de SAML (`sign_authn_requests`, `metadata_url`,
// `metadata_xml`, `email_attribute`) se valida ni se aplica jamás por
// `PATCH /identity-providers/{public_id}` — la petición responde `200`
// sin cambiar nada, en vez de aplicar el cambio o, en este caso
// concreto, devolver el `409` de `RN-AUTH-128`. Los campos comunes
// (`is_enabled`, `display_name`, etc.) no muestran el defecto porque
// están en las dos ramas. Verificado con instrumentación directa
// (log a fichero + inspección de `$validator->getRules()`), no de
// palabra. Severidad Alta (`CLAUDE.md §5`: impide usar por completo la
// firma de peticiones SAML vía API) — no corregido aquí, por instrucción
// explícita de la sesión que encargó estos tests.
test('CA-AUTH-332: sin clave de firma de plataforma configurada, activar sign_authn_requests responde 409', function (): void {
    [$tenant, $admin] = provisionCoreTenant(samlCertsSlug('saml-332'));

    $provider = createActiveSamlProvider($tenant->slug, $admin);

    // phpunit.xml no fija AUTH_SAML_SP_SIGNING_KEY_PATH: vale '' por
    // defecto (config/auth-local.php), el estado real de cualquier
    // despliegue que no haya montado la clave (CA-AUTH-365).
    expect(config('auth-local.saml.sp_signing_key_path'))->toBe('');

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}"), ['sign_authn_requests' => true])
        ->assertStatus(409);

    app(TenantContext::class)->runFor($tenant->id, function () use ($provider): void {
        $settings = DB::table('saml_identity_provider_settings')
            ->join('identity_providers', 'identity_providers.id', '=', 'saml_identity_provider_settings.identity_provider_id')
            ->where('identity_providers.public_id', $provider['public_id'])
            ->first();
        expect($settings->sign_authn_requests)->toBeFalse();
    });
});

// CA-AUTH-333
//
// HALLAZGO REAL, segundo de este lote: este test también falla contra el
// código actual. `IdentityProviderCertificate::$auditRecordedAttributes`
// (app/Modules/Auth/Domain/Models/IdentityProviderCertificate.php)
// incluye `fingerprint_sha256` en la lista de atributos que
// `AuditValuePolicy::Selective` registra CON SU VALOR — así que la huella
// SÍ aparece en claro en `audit_logs.changes`. Esto contradice, en el
// propio árbol de documentación, dos sitios a la vez: `funcional.md`
// (`RN-AUTH-127`: *"Ni el PEM ni la huella de ningún certificado entran
// en audit_logs"*; `CA-AUTH-333` misma) y `datos.md §G.5` (línea ~1740),
// que dice explícitamente lo contrario: *"La huella sí se registra, y es
// lo que responde «¿qué certificado era?» sin copiar el PEM"* — los dos
// documentos raíz del módulo se contradicen entre sí, y el código sigue
// a `datos.md`, no a `funcional.md`. No me corresponde decidir cuál de
// los dos prevalece ni tocar ninguno de los dos por mi cuenta
// (`CLAUDE.md §0`/§11`): se reporta la contradicción documental más el
// comportamiento real, sin silenciar el test.
test('CA-AUTH-333: ni el PEM ni la huella de un certificado aparecen en audit_logs, en claro ni redactados', function (): void {
    [$tenant, $admin] = provisionCoreTenant(samlCertsSlug('saml-333'));

    $provider = createActiveSamlProvider($tenant->slug, $admin);

    $cert = generateSelfSignedTestCertificate();
    $store = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}/certificates"), [
            'certificate' => $cert['cert'],
        ])
        ->assertStatus(201);

    app(TenantContext::class)->runFor($tenant->id, function () use ($store, $cert): void {
        $fingerprint = openssl_x509_fingerprint($cert['cert'], 'sha256');
        $strippedPem = trim((string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $cert['cert']));

        $logs = DB::table('audit_logs')->where('auditable_type', 'identity_provider_certificate')->get();
        expect($logs)->not->toBeEmpty();

        foreach ($logs as $log) {
            $payload = (string) $log->changes;

            // RN-AUTH-127, ADR-043 §3.5.5: `funcional.md §G.11` (CA-AUTH-333)
            // exige que NINGUNA huella aparezca, ni siquiera redactada con
            // su valor. La fila del certificado sí declara
            // `not_before`/`not_after`/`retired_at`/autoría (comprobado
            // más abajo).
            expect($payload)->not->toContain($fingerprint)
                ->not->toContain($strippedPem)
                ->not->toContain(substr($strippedPem, 0, 40));
        }

        // Lo que sí tiene que verse: not_before, not_after y la autoría.
        $createdLog = DB::table('audit_logs')
            ->where('auditable_type', 'identity_provider_certificate')
            ->where('event', 'created')
            ->where('auditable_id', DB::table('identity_provider_certificates')->where('public_id', $store->json('public_id'))->value('id'))
            ->first();
        expect($createdLog)->not->toBeNull()
            ->and($createdLog->actor_user_id)->not->toBeNull();
        expect($createdLog->changes)->toContain('not_before')->toContain('not_after');
    });
});

// CA-AUTH-334
test('CA-AUTH-334: la clave privada de firma del SP no aparece en ninguna respuesta de la API', function (): void {
    [$tenant, $admin] = provisionCoreTenant(samlCertsSlug('saml-334'));

    $provider = createActiveSamlProvider($tenant->slug, $admin);

    // No hay ninguna vía de API que devuelva la clave privada del SP —
    // ni siquiera existe un endpoint para leerla (§G.3.7: solo fichero +
    // variable de entorno, nunca en base de datos). Se comprueba
    // negativamente sobre las dos respuestas que sí hablan del proveedor:
    // el detalle administrativo y sus metadatos de SP publicados.
    $detail = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}"))
        ->assertOk();

    $metadata = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}/metadata"))
        ->assertOk();

    expect(json_encode($detail->json()))->not->toContain('PRIVATE KEY');
    expect((string) $metadata->getContent())->not->toContain('PRIVATE KEY');
});

// CA-AUTH-335
test('CA-AUTH-335: un certificado del IdP a menos de AUTH_SSO_SECRET_EXPIRY_WARNING_DAYS se avisa y la pantalla lo muestra', function (): void {
    [$tenant, $admin] = provisionCoreTenant(samlCertsSlug('saml-335'));

    $provider = createActiveSamlProvider($tenant->slug, $admin);

    app(TenantContext::class)->runFor($tenant->id, function () use ($provider): void {
        $identityProviderId = DB::table('identity_providers')->where('public_id', $provider['public_id'])->value('id');

        DB::table('identity_provider_certificates')
            ->where('identity_provider_id', $identityProviderId)
            ->update(['not_after' => now()->addDays(10)]);
    });

    $detail = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}"))
        ->assertOk();

    // api.md §G.2: la pantalla muestra `vigentes`/`proximo_vencimiento`
    // (no un booleano `expiring_soon` duplicado en el cliente, memory.md
    // "1.4c EN CURSO": el umbral lo dirige el comando diario) — el
    // vencimiento que muestra tiene que ser el que se acaba de fijar.
    expect($detail->json('certificate_status.proximo_vencimiento'))->not->toBeNull()
        ->and(Carbon::parse($detail->json('certificate_status.proximo_vencimiento'))->diffInDays(now()))->toBeLessThan(11);

    test()->artisan('auth:warn-expiring-idp-certificates')->assertSuccessful();
});
