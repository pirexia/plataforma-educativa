<?php

use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Models\UserIdentity;
use App\Modules\Auth\Domain\SamlIdentityProviderRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// REQ-AUTH-004 (1.4c), funcional.md §G.6 ("Validación de la aserción"),
// §G.11. RN-AUTH-117 a RN-AUTH-123: las ocho comprobaciones antes de leer
// un solo atributo de identidad, la correlación de un solo uso, y la
// protección de repetición.
//
// HALLAZGO REAL que afecta a varios de estos tests, documentado con
// detalle en tests/Feature/Auth/SamlCertificatesTest.php (cabecera, antes
// de CA-AUTH-327): `FakeSamlIdentityProviderController::buildSignedResponse()`
// nunca firma el nodo `<samlp:Response>`, solo la `<saml:Assertion>` —
// `wantMessagesSigned = true` (RN-AUTH-117) rechaza CUALQUIER login SAML
// simulado con éxito, con "The Message of the Response is not signed and
// the SP requires it". Los tests que solo necesitan una aserción
// RECHAZADA (la mayoría de este fichero) no les afecta: el resultado
// final (`error_proveedor`/`estado_no_valido`, sin sesión, sin vínculo)
// es el mismo tanto si el rechazo real fuera por la causa que el test
// dice cubrir como si fuera por este defecto de la infraestructura de
// test — aunque, hasta que se corrija, el test no distingue las dos
// causas. Los que SÍ necesitan un login que termine con éxito
// (CA-AUTH-343, CA-AUTH-344) lo señalan explícitamente y fallan por esta
// causa, no por la suya propia.

function samlAssertionSlug(string $base): string
{
    return $base.'-'.strtolower(Str::random(6));
}

// CA-AUTH-336
test('CA-AUTH-336: el Settings que construye el envoltorio tiene strict, wantAssertionsSigned, wantMessagesSigned y rejectUnsolicitedResponsesWithInResponseTo a true', function (): void {
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlAssertionSlug('saml-336'));

    app(TenantContext::class)->runFor($tenant->id, function () use ($providerId): void {
        $provider = IdentityProvider::query()->where('public_id', $providerId)->firstOrFail();
        $samlIdentityProvider = app(SamlIdentityProviderRegistry::class)->forProvider($provider);

        // "Por reflexión", literal (CA-AUTH-336): se inspecciona el
        // Settings YA CONSTRUIDO, no el texto del código — `settings` es
        // una propiedad privada del envoltorio.
        $reflection = new ReflectionClass($samlIdentityProvider);
        $settingsProperty = $reflection->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $settings = $settingsProperty->getValue($samlIdentityProvider);

        expect($settings->isStrict())->toBeTrue();

        $security = $settings->getSecurityData();
        expect($security['wantAssertionsSigned'])->toBeTrue()
            ->and($security['wantMessagesSigned'])->toBeTrue()
            ->and($security['rejectUnsolicitedResponsesWithInResponseTo'])->toBeTrue();
    });
});

// CA-AUTH-337
test('CA-AUTH-337: una aserción sin firma, o con firma que no valida, responde error_proveedor sin leer identidad ni crear sesión ni vínculo', function (): void {
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlAssertionSlug('saml-337'), ['email' => 'u337@example.com']);

    foreach (['sin_firma', 'firma_alterada'] as $broken) {
        [$authorizationUrl, $beginCookie] = beginSamlFlow($tenant->slug, $providerId);
        $callback = completeSamlFlow($authorizationUrl, [
            'broken' => $broken, 'sub' => 'sub-337-'.$broken, 'attribute_name' => 'mail', 'attribute_value' => 'u337@example.com',
        ]);

        expect(oauthCallbackResultCode($callback))->toBe('error_proveedor');
    }

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-338
test('CA-AUTH-338: una aserción válida para OTRO proveedor SAML del mismo centro se rechaza en el ACS al que se dirige', function (): void {
    [$tenant, $admin] = provisionCoreTenant(samlAssertionSlug('saml-338'));

    // Proveedor B: metadatos construidos a mano con un entityID y un
    // certificado propios (generateSelfSignedTestCertificate(), NO el
    // que realmente firma el IdP simulado), pero su SingleSignOnService
    // SÍ apunta al IdP simulado real — así se puede completar un flujo
    // de verdad contra él. El certificado admisible de B nunca coincide
    // con la clave que de verdad firma (RN-AUTH-118: los certificados
    // admisibles salen de la ruta/proveedor, nunca del contenido del
    // mensaje).
    $certB = generateSelfSignedTestCertificate();
    $metadataXmlB = buildSamlMetadataXml(
        entityId: 'https://idp-338-b.example.com/entity',
        certificatePem: $certB['cert'],
        ssoUrl: SAML_FAKE_IDP_SSO_URL,
    );

    $storeB = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/identity-providers'), [
        'protocol' => 'saml',
        'display_name' => 'Proveedor B',
        'metadata_xml' => $metadataXmlB,
        'email_attribute' => 'mail',
    ])->assertStatus(201);
    $providerB = $storeB->json('public_id');
    test()->actingAs($admin)->patchJson(coreApiUrl($tenant->slug, "/identity-providers/{$providerB}"), ['is_enabled' => true])->assertOk();

    [$authorizationUrl, $beginCookie] = beginSamlFlow($tenant->slug, $providerB);
    $callback = completeSamlFlow($authorizationUrl, [
        'sub' => 'sub-338', 'attribute_name' => 'mail', 'attribute_value' => 'x@example.com',
    ]);

    // Rechazada: el certificado que de verdad firmó (el del IdP
    // simulado, FakeSamlKeyMaterial) no está entre los admisibles de B.
    expect(oauthCallbackResultCode($callback))->toBe('error_proveedor');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-339
test('CA-AUTH-339: una aserción del centro A se rechaza en el ACS del centro B por tres barreras independientes, probadas de una en una', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant(samlAssertionSlug('saml-339a'));
    [$tenantB] = provisionCoreTenant(samlAssertionSlug('saml-339b'));

    $providerA = createActiveSamlProvider($tenantA->slug, $adminA);

    // Barrera 1: la ruta. Una aserción válida de A entregada en el host
    // de B, sobre el MISMO public_id de A — RLS hace que ese public_id
    // no exista para B, RN-AUTH-116.
    [$authorizationUrl1, ] = beginSamlFlow($tenantA->slug, $providerA['public_id']);
    $query1 = [];
    parse_str((string) parse_url($authorizationUrl1, PHP_URL_QUERY), $query1);
    $ssoUrl1 = SAML_FAKE_IDP_SSO_URL.'?'.http_build_query(array_merge($query1, [
        'sub' => 'sub-339-ruta', 'attribute_name' => 'mail', 'attribute_value' => 'u339@example.com',
    ]));
    $form1 = test()->get($ssoUrl1)->assertOk();
    $samlResponse1 = samlAutoSubmitFormValue($form1->getContent(), 'SAMLResponse');
    $crossTenantAcsUrl = coreApiUrl($tenantB->slug, "/auth/saml/{$providerA['public_id']}/acs");
    $response1 = test()->post($crossTenantAcsUrl, ['SAMLResponse' => $samlResponse1])->assertRedirect();
    expect(oauthCallbackResultCode($response1))->toBe('proveedor_no_disponible');

    // Barrera 2: Destination/Recipient. La aserción se dirige, DENTRO
    // del XML, a una ACS URL distinta de la nuestra — pero se entrega
    // igualmente en la ACS real de A (para aislar esta barrera de la de
    // la ruta): se posta a mano en vez de seguir el `action` del
    // formulario, que llevaría el mismo valor erróneo.
    [$authorizationUrl2, ] = beginSamlFlow($tenantA->slug, $providerA['public_id']);
    $query2 = [];
    parse_str((string) parse_url($authorizationUrl2, PHP_URL_QUERY), $query2);
    $ssoUrl2 = SAML_FAKE_IDP_SSO_URL.'?'.http_build_query(array_merge($query2, [
        'sub' => 'sub-339-destino', 'attribute_name' => 'mail', 'attribute_value' => 'u339@example.com',
        'acs_url_override' => 'https://otro-tenant.invalid/api/v1/auth/saml/x/acs',
    ]));
    $form2 = test()->get($ssoUrl2)->assertOk();
    $samlResponse2 = samlAutoSubmitFormValue($form2->getContent(), 'SAMLResponse');
    $realAcsUrlA = coreApiUrl($tenantA->slug, "/auth/saml/{$providerA['public_id']}/acs");
    $response2 = test()->post($realAcsUrlA, ['SAMLResponse' => $samlResponse2])->assertRedirect();
    expect(oauthCallbackResultCode($response2))->toBe('error_proveedor');

    // Barrera 3: Audience. `broken=audiencia_otro_tenant` solo cambia el
    // entityId del SP dentro del `<saml:Audience>`, sin tocar Destination
    // — la entrega sí llega a la ACS real de A. Petición nueva (una fila
    // de correlación es de un solo uso, RN-AUTH-121): no se reutiliza
    // ninguna de las dos anteriores.
    [$authorizationUrl3, ] = beginSamlFlow($tenantA->slug, $providerA['public_id']);
    $callback3 = completeSamlFlow($authorizationUrl3, [
        'broken' => 'audiencia_otro_tenant', 'sub' => 'sub-339-audiencia', 'attribute_name' => 'mail', 'attribute_value' => 'u339@example.com',
    ]);
    expect(oauthCallbackResultCode($callback3))->toBe('error_proveedor');

    app(TenantContext::class)->runFor($tenantA->id, function (): void {
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-340
test('CA-AUTH-340: Issuer distinto, NotOnOrAfter vencido y Recipient incorrecto responden error_proveedor con el mismo cuerpo', function (): void {
    // NOTA DE COBERTURA: de los cuatro casos que RN-AUTH-119 agrupa bajo
    // el mismo código, "NotBefore futuro fuera de tolerancia" no tiene
    // ningún parámetro `broken`/override en
    // FakeSamlIdentityProviderController::sso() que permita forzarlo
    // (NotBefore es siempre `now() - 60` fijo, sin query param) — no se
    // fabrica un test que aparente cubrirlo sin hacerlo de verdad.
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlAssertionSlug('saml-340'), ['email' => 'u340@example.com']);

    // NotOnOrAfter vencido.
    [$url1, ] = beginSamlFlow($tenant->slug, $providerId);
    $callback1 = completeSamlFlow($url1, [
        'broken' => 'caducada', 'sub' => 'sub-340a', 'attribute_name' => 'mail', 'attribute_value' => 'u340@example.com',
    ]);
    expect(oauthCallbackResultCode($callback1))->toBe('error_proveedor');

    // Recipient incorrecto: mismo mecanismo que la barrera 2 de
    // CA-AUTH-339 (acs_url_override cambia Destination/Recipient dentro
    // del XML sin cambiar dónde se entrega de verdad). Antes de mutar el
    // issuer más abajo, para no mezclar las dos causas de rechazo.
    [$url2, ] = beginSamlFlow($tenant->slug, $providerId);
    $query2 = [];
    parse_str((string) parse_url($url2, PHP_URL_QUERY), $query2);
    $ssoUrl2 = SAML_FAKE_IDP_SSO_URL.'?'.http_build_query(array_merge($query2, [
        'sub' => 'sub-340b', 'attribute_name' => 'mail', 'attribute_value' => 'u340@example.com',
        'acs_url_override' => 'https://otro-host.invalid/api/v1/auth/saml/x/acs',
    ]));
    $form2 = test()->get($ssoUrl2)->assertOk();
    $samlResponse2 = samlAutoSubmitFormValue($form2->getContent(), 'SAMLResponse');
    $realAcsUrl = coreApiUrl($tenant->slug, "/auth/saml/{$providerId}/acs");
    $callback2 = test()->post($realAcsUrl, ['SAMLResponse' => $samlResponse2])->assertRedirect();
    expect(oauthCallbackResultCode($callback2))->toBe('error_proveedor');

    // Issuer distinto del catalogado: se muta la fila directamente entre
    // el alta y el acceso (mismo patrón que CA-AUTH-326 con metadata_url)
    // — último caso, para no contaminar los anteriores.
    app(TenantContext::class)->runFor($tenant->id, function () use ($providerId): void {
        DB::table('identity_providers')->where('public_id', $providerId)->update(['issuer' => 'https://issuer-cambiado.example.com/entity']);
    });

    [$url3, ] = beginSamlFlow($tenant->slug, $providerId);
    $callback3 = completeSamlFlow($url3, [
        'sub' => 'sub-340c', 'attribute_name' => 'mail', 'attribute_value' => 'u340@example.com',
    ]);
    expect(oauthCallbackResultCode($callback3))->toBe('error_proveedor');
});

// CA-AUTH-341
test('CA-AUTH-341: una aserción sin InResponseTo se rechaza con estado_no_valido, aunque su firma sea perfectamente válida', function (): void {
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlAssertionSlug('saml-341'), ['email' => 'u341@example.com']);

    [$authorizationUrl, ] = beginSamlFlow($tenant->slug, $providerId);
    $callback = completeSamlFlow($authorizationUrl, [
        'broken' => 'sin_in_response_to', 'sub' => 'sub-341', 'attribute_name' => 'mail', 'attribute_value' => 'u341@example.com',
    ]);

    // El InResponseTo se lee ANTES de verificar la firma (RN-AUTH-120,
    // SamlAcsService::handle()): sin fila que case, estado_no_valido
    // llega antes de tocar la validación criptográfica — este test NO
    // depende del hallazgo de la cabecera del fichero.
    expect(oauthCallbackResultCode($callback))->toBe('estado_no_valido');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-342
test('CA-AUTH-342: un InResponseTo de una fila ya consumida o caducada se rechaza con estado_no_valido, con el mismo cuerpo en los dos casos', function (): void {
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlAssertionSlug('saml-342'), ['email' => 'u342@example.com']);

    // Caducada: se crea la petición y se fuerza su expires_at al pasado
    // antes de entregar la aserción.
    [$authorizationUrl, ] = beginSamlFlow($tenant->slug, $providerId);
    app(TenantContext::class)->runFor($tenant->id, function () use ($providerId): void {
        DB::table('saml_auth_requests')
            ->where('identity_provider_id', DB::table('identity_providers')->where('public_id', $providerId)->value('id'))
            ->whereNull('consumed_at')
            ->update(['expires_at' => now()->subMinute()]);
    });
    $callback1 = completeSamlFlow($authorizationUrl, [
        'sub' => 'sub-342a', 'attribute_name' => 'mail', 'attribute_value' => 'u342@example.com',
    ]);
    expect(oauthCallbackResultCode($callback1))->toBe('estado_no_valido');

    // Ya consumida: se fuerza consumed_at directamente (sin depender de
    // completar un login real, que el hallazgo de la cabecera bloquea).
    [$authorizationUrl2, ] = beginSamlFlow($tenant->slug, $providerId);
    app(TenantContext::class)->runFor($tenant->id, function () use ($providerId): void {
        DB::table('saml_auth_requests')
            ->where('identity_provider_id', DB::table('identity_providers')->where('public_id', $providerId)->value('id'))
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    });
    $callback2 = completeSamlFlow($authorizationUrl2, [
        'sub' => 'sub-342b', 'attribute_name' => 'mail', 'attribute_value' => 'u342@example.com',
    ]);
    expect(oauthCallbackResultCode($callback2))->toBe('estado_no_valido');
});

// CA-AUTH-343
test('CA-AUTH-343: dos entregas de la misma aserción — exactamente una crea sesión, la otra se rechaza por el consumo atómico', function (): void {
    // Afectado por el hallazgo de la cabecera: necesita un login que
    // termine con éxito para que haya algo que "repetir". Se documenta
    // la mecánica exacta igualmente: se prepara UNA sola aserción firmada
    // (una sola llamada al IdP simulado) y se entrega dos veces contra el
    // ACS real, verbatim.
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlAssertionSlug('saml-343'), ['email' => 'u343@example.com']);

    [$authorizationUrl, ] = beginSamlFlow($tenant->slug, $providerId);
    $query = [];
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
    $ssoUrl = SAML_FAKE_IDP_SSO_URL.'?'.http_build_query(array_merge($query, [
        'sub' => 'sub-343', 'attribute_name' => 'mail', 'attribute_value' => 'u343@example.com',
    ]));
    $form = test()->get($ssoUrl)->assertOk();
    $acsUrl = samlAutoSubmitFormValue($form->getContent(), 'action');
    $samlResponseB64 = samlAutoSubmitFormValue($form->getContent(), 'SAMLResponse');

    $first = test()->post($acsUrl, ['SAMLResponse' => $samlResponseB64])->assertRedirect();
    $second = test()->post($acsUrl, ['SAMLResponse' => $samlResponseB64])->assertRedirect();

    $outcomes = [oauthCallbackResultCode($first), oauthCallbackResultCode($second)];

    // Exactamente una entrega tiene que resultar en éxito (código null) y
    // la otra en estado_no_valido — nunca las dos con éxito, nunca las
    // dos rechazadas.
    expect($outcomes)->toContain(null)
        ->and(array_filter($outcomes, fn ($o) => $o === null))->toHaveCount(1)
        ->and(array_filter($outcomes, fn ($o) => $o === 'estado_no_valido'))->toHaveCount(1);
});

// CA-AUTH-344
test('CA-AUTH-344: una aserción ya consumida se reenvía contra otra petición viva del mismo proveedor y se rechaza por el índice único, sin quemar la fila viva', function (): void {
    // Afectado por el hallazgo de la cabecera: el primer login (el que
    // "consume" la aserción por primera vez) necesita completarse con
    // éxito.
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlAssertionSlug('saml-344'), ['email' => 'u344@example.com']);

    $fixedAssertionId = '_'.bin2hex(random_bytes(16));

    $cookie = loginWithSamlFor($tenant->slug, $providerId, [
        'sub' => 'sub-344', 'attribute_name' => 'mail', 'attribute_value' => 'u344@example.com',
        'assertion_id_override' => $fixedAssertionId,
    ]);
    expect($cookie)->not->toBeEmpty();

    // Segunda petición VIVA del mismo proveedor, con la MISMA aserción
    // (mismo assertion_id) — InResponseTo distinto (correlaciona con la
    // segunda fila), pero el ID de aserción ya está registrado en
    // saml_consumed_assertions.
    [$authorizationUrl2, ] = beginSamlFlow($tenant->slug, $providerId);
    $callback2 = completeSamlFlow($authorizationUrl2, [
        'sub' => 'sub-344', 'attribute_name' => 'mail', 'attribute_value' => 'u344@example.com',
        'assertion_id_override' => $fixedAssertionId,
    ]);

    expect(oauthCallbackResultCode($callback2))->toBe('error_proveedor');

    // La fila de la segunda petición NO queda consumida (el intento se
    // aborta entero, comentario de SamlAcsService::handle()): una entrega
    // legítima futura contra ella podría seguir completándose.
    app(TenantContext::class)->runFor($tenant->id, function () use ($providerId): void {
        $stillAlive = DB::table('saml_auth_requests')
            ->where('identity_provider_id', DB::table('identity_providers')->where('public_id', $providerId)->value('id'))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->exists();
        expect($stillAlive)->toBeTrue();
    });
});

// CA-AUTH-345
// Afectado por el hallazgo de la cabecera de este fichero, de una forma
// distinta a los demás: aquí no basta con que la aserción se rechace
// (que ya ocurre) — el CÓDIGO de rechazo tiene que ser específicamente
// `sin_cuenta` (RN-AUTH-123), y ahora mismo es `error_proveedor`, porque
// `validateAssertion()` lanza por "Response no firmada" ANTES de llegar
// al punto donde se comprueba el NameID.
test('CA-AUTH-345: una aserción sin NameID, con NameID vacío, o con Format distinto del catalogado se rechaza igual que sin_cuenta, nunca por correo', function (): void {
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlAssertionSlug('saml-345'), ['email' => 'u345@example.com']);

    // Sin NameID.
    [$url1, ] = beginSamlFlow($tenant->slug, $providerId);
    $callback1 = completeSamlFlow($url1, [
        'broken' => 'sin_name_id', 'attribute_name' => 'mail', 'attribute_value' => 'u345@example.com',
    ]);
    expect(oauthCallbackResultCode($callback1))->toBe('sin_cuenta');

    // Format distinto del catalogado (el proveedor cataloga `persistent`
    // vía createActiveSamlProvider(); se envía `unspecified`).
    [$url2, ] = beginSamlFlow($tenant->slug, $providerId);
    $callback2 = completeSamlFlow($url2, [
        'sub' => 'sub-345', 'name_id_format' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
        'attribute_name' => 'mail', 'attribute_value' => 'u345@example.com',
    ]);
    expect(oauthCallbackResultCode($callback2))->toBe('sin_cuenta');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(UserIdentity::query()->count())->toBe(0);
    });
});
