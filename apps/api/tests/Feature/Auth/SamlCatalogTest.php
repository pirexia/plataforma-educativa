<?php

use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// REQ-AUTH-004 (1.4c), funcional.md Parte G, api.md Parte G, datos.md
// Parte G. Catálogo de proveedores SAML por tenant: el discriminador
// `protocol` sobre una tabla viva, compatibilidad con la versión
// anterior, aislamiento por tenant, y la validación síncrona de los
// metadatos del IdP (las guardas de §G.4.2).

// CA-AUTH-311
test('CA-AUTH-311: una fila protocol=saml con cualquier columna OIDC informada la impide el CHECK, no el servicio', function (): void {
    $tenant = app(TenantContext::class);

    foreach (['discovery_url', 'token_endpoint', 'client_id', 'email_claim', 'claims_source', 'userinfo_endpoint'] as $column) {
        [$tenantRow] = provisionCoreTenant('saml-311-'.Str::random(4));

        $columnValue = match ($column) {
            'email_claim' => 'email',
            'claims_source' => 'id_token',
            default => 'x',
        };

        // La comprobación tiene que correr con el tenant aplicado a la
        // conexión: sin él, la RLS bloquea el INSERT antes de que el CHECK
        // llegue a evaluarse, y expect(...)->toThrow() "pasaría" sin haber
        // ejercitado el CHECK que este test dice cubrir (INV-015).
        //
        // DB::transaction() alrededor del INSERT: sin ella, un INSERT que
        // falla dentro de la transacción por test de DatabaseTransactions
        // deja la conexión "in failed sql transaction" (SQLSTATE 25P02) y
        // el propio finally de TenantContext::runFor() (que reaplica el
        // GUC de tenant) revienta con ese mismo estado — un fallo de
        // aislamiento del test, no del CHECK que se quiere probar. Al
        // anidar dentro de la transacción de nivel test, Laravel usa un
        // SAVEPOINT: el rollback automático de la transacción fallida
        // limpia solo ese SAVEPOINT y la conexión queda utilizable para la
        // siguiente iteración y para el propio runFor().
        $insert = fn () => app(TenantContext::class)->runFor($tenantRow->id, fn () => DB::transaction(fn () => DB::table('identity_providers')->insert([
            'tenant_id' => $tenantRow->id,
            'public_id' => (string) Str::ulid(),
            'protocol' => 'saml',
            'display_name' => 'SAML roto',
            'issuer' => 'https://idp-311.example.com/entity',
            'authorization_endpoint' => 'https://idp-311.example.com/sso',
            $column => $columnValue,
            'created_at' => now(),
            'updated_at' => now(),
        ])));

        expect($insert)->toThrow(QueryException::class);
    }
});

// CA-AUTH-312
test('CA-AUTH-312: una fila protocol=oidc con una columna obligatoria OIDC a NULL la impide el CHECK condicionado', function (): void {
    [$tenantRow] = provisionCoreTenant('saml-312');

    $requiredColumns = [
        'discovery_url' => 'https://idp.example.com/.well-known/openid-configuration',
        'token_endpoint' => 'https://idp.example.com/token',
        'client_id' => 'client-x',
        'scopes' => '["openid"]',
        'email_claim' => 'email',
        'claims_source' => 'id_token',
        'discovery_fetched_at' => now(),
    ];

    foreach (array_keys($requiredColumns) as $missing) {
        $row = [
            'tenant_id' => $tenantRow->id,
            'public_id' => (string) Str::ulid(),
            'protocol' => 'oidc',
            'display_name' => 'OIDC incompleto',
            'issuer' => 'https://idp-312.example.com',
            'authorization_endpoint' => 'https://idp-312.example.com/authorize',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach ($requiredColumns as $column => $value) {
            if ($column === $missing) {
                continue;
            }

            $row[$column] = $column === 'scopes' ? DB::raw("'{$value}'::jsonb") : $value;
        }

        // Mismo motivo que CA-AUTH-311: sin runFor(), la RLS bloquearía el
        // INSERT antes de que el CHECK condicionado llegara a evaluarse. Y
        // el DB::transaction() interior es el mismo motivo que CA-AUTH-311:
        // un SAVEPOINT en vez de dejar la conexión en "failed sql
        // transaction" para la siguiente iteración del bucle.
        expect(fn () => app(TenantContext::class)->runFor($tenantRow->id, fn () => DB::transaction(fn () => DB::table('identity_providers')->insert($row))))
            ->toThrow(QueryException::class, null);
    }
});

// CA-AUTH-313
test('CA-AUTH-313: una fila SAML creada por el servicio deja scopes, claims_source y email_claim a NULL, no con el valor OIDC por defecto', function (): void {
    [$tenant, $admin] = provisionCoreTenant('saml-313');

    $provider = createActiveSamlProvider($tenant->slug, $admin);

    app(TenantContext::class)->runFor($tenant->id, function () use ($provider): void {
        $row = DB::table('identity_providers')->where('public_id', $provider['public_id'])->first();

        expect($row->scopes)->toBeNull()
            ->and($row->claims_source)->toBeNull()
            ->and($row->email_claim)->toBeNull()
            ->and($row->discovery_url)->toBeNull()
            ->and($row->token_endpoint)->toBeNull()
            ->and($row->client_id)->toBeNull();
    });
});

// CA-AUTH-314
test('CA-AUTH-314: una fila insertada sin nombrar protocol (como haría la versión anterior) queda protocol=oidc', function (): void {
    [$tenantRow] = provisionCoreTenant('saml-314');

    // runFor(): sin el tenant aplicado a la conexión, la RLS bloquearía
    // este INSERT (mismo motivo que CA-AUTH-311/312) y el test fallaría
    // por una causa distinta de la que dice cubrir.
    app(TenantContext::class)->runFor($tenantRow->id, function () use ($tenantRow): void {
        DB::table('identity_providers')->insert([
            'tenant_id' => $tenantRow->id,
            'public_id' => (string) Str::ulid(),
            // Sin 'protocol': la aplicación de 1.4b no la nombra en ningún
            // INSERT. El DEFAULT 'oidc' la rellena (datos.md §G.7.2).
            'display_name' => 'Fila de la versión anterior',
            'issuer' => 'https://idp-314.example.com',
            'authorization_endpoint' => 'https://idp-314.example.com/authorize',
            'discovery_url' => 'https://idp-314.example.com/.well-known/openid-configuration',
            'token_endpoint' => 'https://idp-314.example.com/token',
            'client_id' => 'client-314',
            'scopes' => DB::raw("'[\"openid\"]'::jsonb"),
            'email_claim' => 'email',
            'claims_source' => 'id_token',
            'discovery_fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    app(TenantContext::class)->runFor($tenantRow->id, function (): void {
        $row = DB::table('identity_providers')->where('issuer', 'https://idp-314.example.com')->first();
        expect($row->protocol)->toBe('oidc');
    });
});

// CA-AUTH-315
test('CA-AUTH-315: un issuer ya catalogado como OIDC no puede catalogarse también como SAML, 409', function (): void {
    [$tenant, $admin] = provisionCoreTenant('saml-315');

    $oidc = createActiveOidcProvider($tenant->slug, $admin);

    $issuer = app(TenantContext::class)->runFor($tenant->id, function () use ($oidc) {
        return DB::table('identity_providers')->where('public_id', $oidc['public_id'])->value('issuer');
    });

    $cert = generateSelfSignedTestCertificate();
    $metadataXml = buildSamlMetadataXml(entityId: $issuer, certificatePem: $cert['cert']);

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/identity-providers'), [
        'protocol' => 'saml',
        'display_name' => 'Mismo emisor, otro protocolo',
        'metadata_xml' => $metadataXml,
        'email_attribute' => 'mail',
    ])->assertStatus(409);

    app(TenantContext::class)->runFor($tenant->id, function () use ($issuer): void {
        expect(IdentityProvider::query()->where('issuer', $issuer)->count())->toBe(1);
    });
});

// CA-AUTH-316
test('CA-AUTH-316: un PATCH que trae protocol responde 422 y no cambia nada, aunque el valor coincida con el actual', function (): void {
    [$tenant, $admin] = provisionCoreTenant('saml-316');

    $provider = createActiveSamlProvider($tenant->slug, $admin);

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}"), [
            'protocol' => 'saml',
            'display_name' => 'Nombre que no debería aplicarse',
        ])
        ->assertStatus(422);

    app(TenantContext::class)->runFor($tenant->id, function () use ($provider): void {
        $row = IdentityProvider::query()->where('public_id', $provider['public_id'])->firstOrFail();
        expect($row->display_name)->toBe('Proveedor SAML de prueba');
    });
});

// CA-AUTH-317
test('CA-AUTH-317: un public_id de proveedor SAML de otro tenant responde 404, nunca 403, en las cinco rutas de administración', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('saml-317a');
    [$tenantB, $adminB] = provisionCoreTenant('saml-317b');

    $providerB = createActiveSamlProvider($tenantB->slug, $adminB);
    $publicId = $providerB['public_id'];

    $certificate = app(TenantContext::class)->runFor($tenantB->id, function () use ($publicId) {
        return DB::table('identity_provider_certificates')
            ->join('identity_providers', 'identity_providers.id', '=', 'identity_provider_certificates.identity_provider_id')
            ->where('identity_providers.public_id', $publicId)
            ->value('identity_provider_certificates.public_id');
    });

    test()->actingAs($adminA)
        ->getJson(coreApiUrl($tenantA->slug, "/identity-providers/{$publicId}"))
        ->assertNotFound();

    test()->actingAs($adminA)
        ->patchJson(coreApiUrl($tenantA->slug, "/identity-providers/{$publicId}"), ['display_name' => 'x'])
        ->assertNotFound();

    test()->actingAs($adminA)
        ->getJson(coreApiUrl($tenantA->slug, "/identity-providers/{$publicId}/metadata"))
        ->assertNotFound();

    test()->actingAs($adminA)
        ->postJson(coreApiUrl($tenantA->slug, "/identity-providers/{$publicId}/certificates"), [
            'certificate' => generateSelfSignedTestCertificate()['cert'],
        ])
        ->assertNotFound();

    test()->actingAs($adminA)
        ->deleteJson(coreApiUrl($tenantA->slug, "/identity-providers/{$publicId}/certificates/{$certificate}"))
        ->assertNotFound();

    test()->actingAs($adminA)
        ->postJson(coreApiUrl($tenantA->slug, "/identity-providers/{$publicId}/metadata-refreshes"))
        ->assertNotFound();

    app(TenantContext::class)->runFor($tenantB->id, function () use ($publicId): void {
        expect(IdentityProvider::query()->where('public_id', $publicId)->exists())->toBeTrue();
    });
});

// CA-AUTH-318
test('CA-AUTH-318: un XML de metadatos con DOCTYPE se rechaza en el analizador con metadatos_no_validos', function (): void {
    [$tenant, $admin] = provisionCoreTenant('saml-318');

    $xxe = <<<'XML'
        <?xml version="1.0"?>
        <!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
        <md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="https://idp-xxe.example.com/entity">
          <md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
            <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="&xxe;"/>
          </md:IDPSSODescriptor>
        </md:EntityDescriptor>
        XML;

    $response = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/identity-providers'), [
        'protocol' => 'saml',
        'display_name' => 'XXE',
        'metadata_xml' => $xxe,
        'email_attribute' => 'mail',
    ])->assertStatus(422);

    expect($response->json('errors.metadata_url.0.code'))->toBe('auth.saml.metadata.metadatos_no_validos');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(IdentityProvider::query()->count())->toBe(0);
    });
});

// CA-AUTH-319
test('CA-AUTH-319: una URL de metadatos que resuelve a una dirección privada se rechaza sin realizar la petición', function (): void {
    [$tenant, $admin] = provisionCoreTenant('saml-319');

    foreach (['https://127.0.0.1/x', 'https://169.254.169.254/x', 'https://10.0.0.1/x'] as $url) {
        config(['auth-local.saml.allow_insecure_metadata' => false]);

        $response = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/identity-providers'), [
            'protocol' => 'saml',
            'display_name' => 'Proveedor privado',
            'metadata_url' => $url,
            'email_attribute' => 'mail',
        ])->assertStatus(422);

        expect($response->json('errors.metadata_url.0.code'))->toBe('auth.saml.metadata.destino_no_publico');
    }

    config(['auth-local.saml.allow_insecure_metadata' => true]);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(IdentityProvider::query()->count())->toBe(0);
    });
});

// CA-AUTH-321
test('CA-AUTH-321: unos metadatos sin SingleSignOnService HTTP-Redirect responden binding_no_admitido y no crean nada', function (): void {
    [$tenant, $admin] = provisionCoreTenant('saml-321');

    $cert = generateSelfSignedTestCertificate();
    $xml = buildSamlMetadataXml(certificatePem: $cert['cert'], ssoBinding: 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST');

    $response = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/identity-providers'), [
        'protocol' => 'saml',
        'display_name' => 'Solo POST',
        'metadata_xml' => $xml,
        'email_attribute' => 'mail',
    ])->assertStatus(422);

    expect($response->json('errors.metadata_url.0.code'))->toBe('auth.saml.metadata.binding_no_admitido');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(IdentityProvider::query()->count())->toBe(0);
    });
});

// CA-AUTH-322
test('CA-AUTH-322: unos metadatos sin KeyDescriptor de firma, o con el único certificado ya caducado, responden sin_certificado_de_firma', function (): void {
    [$tenant, $admin] = provisionCoreTenant('saml-322');

    // Sin ningún KeyDescriptor.
    $withoutCert = buildSamlMetadataXml();

    $response = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/identity-providers'), [
        'protocol' => 'saml',
        'display_name' => 'Sin certificado',
        'metadata_xml' => $withoutCert,
        'email_attribute' => 'mail',
    ])->assertStatus(422);
    expect($response->json('errors.metadata_url.0.code'))->toBe('auth.saml.metadata.sin_certificado_de_firma');

    // Con un único certificado, ya caducado (viaje en el tiempo tras
    // generarlo con vigencia corta: RN-AUTH-126, ningún parámetro de
    // openssl_csr_sign() admite una vigencia ya pasada directamente).
    $expired = generateSelfSignedTestCertificate(days: 1);
    Carbon::setTestNow(now()->addDays(2));
    $expiredXml = buildSamlMetadataXml(certificatePem: $expired['cert']);

    $response2 = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/identity-providers'), [
        'protocol' => 'saml',
        'display_name' => 'Certificado caducado',
        'metadata_xml' => $expiredXml,
        'email_attribute' => 'mail',
    ])->assertStatus(422);
    expect($response2->json('errors.metadata_url.0.code'))->toBe('auth.saml.metadata.sin_certificado_de_firma');

    Carbon::setTestNow();

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(IdentityProvider::query()->count())->toBe(0);
    });
});

// CA-AUTH-323
test('CA-AUTH-323: unos metadatos que declaran NameIDFormat transient responden 422, formato_de_identificador_no_admitido', function (): void {
    [$tenant, $admin] = provisionCoreTenant('saml-323');

    $cert = generateSelfSignedTestCertificate();
    $xml = buildSamlMetadataXml(
        certificatePem: $cert['cert'],
        nameIdFormats: ['urn:oasis:names:tc:SAML:2.0:nameid-format:transient'],
    );

    $response = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/identity-providers'), [
        'protocol' => 'saml',
        'display_name' => 'Transient',
        'metadata_xml' => $xml,
        'email_attribute' => 'mail',
    ])->assertStatus(422);

    expect($response->json('errors.metadata_url.0.code'))->toBe('auth.saml.metadata.formato_de_identificador_no_admitido');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(IdentityProvider::query()->count())->toBe(0);
    });
});

// CA-AUTH-324
test('CA-AUTH-324: unos metadatos válidos dejan issuer=entityID, authorization_endpoint=URL HTTP-Redirect, una fila de certificado por KeyDescriptor, y el proveedor nace no activo sin firmar peticiones', function (): void {
    [$tenant, $admin] = provisionCoreTenant('saml-324');

    // No se usa createActiveSamlProvider(): activa el proveedor con un
    // PATCH is_enabled=true justo después de crearlo, y este test necesita
    // observar el estado recién creado (no activo, is_enabled=false) antes
    // de esa activación. Además, createActiveSamlProvider() apunta al
    // mismo SAML_FAKE_IDP_METADATA_URL que este alta manual: llamar a las
    // dos catalogaría el mismo entityID dos veces en el mismo tenant y la
    // segunda fallaría con 409 (emisor_ya_catalogado, UNIQUE(tenant_id,
    // issuer)) en vez de 201 — el fallo real que tenía este test.
    $store = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/identity-providers'), [
        'protocol' => 'saml',
        'display_name' => 'CA-324-alta',
        'metadata_url' => SAML_FAKE_IDP_METADATA_URL,
        'email_attribute' => 'mail',
    ])->assertStatus(201);

    expect($store->json('is_enabled'))->toBeFalse()
        ->and($store->json('protocol'))->toBe('saml')
        ->and($store->json('issuer'))->toContain('/_sso-simulator/saml/entity')
        ->and($store->json('authorization_endpoint'))->toBe('http://localhost:8000/api/_sso-simulator/saml/sso');

    $detail = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/identity-providers/{$store->json('public_id')}"))
        ->assertOk();

    expect($detail->json('sign_authn_requests'))->toBeFalse()
        ->and($detail->json('certificates'))->toHaveCount(1);

    app(TenantContext::class)->runFor($tenant->id, function () use ($store): void {
        $count = DB::table('identity_provider_certificates')
            ->join('identity_providers', 'identity_providers.id', '=', 'identity_provider_certificates.identity_provider_id')
            ->where('identity_providers.public_id', $store->json('public_id'))
            ->count();
        expect($count)->toBe(1);
    });
});

// CA-AUTH-326
test('CA-AUTH-326: un refresco de metadatos que falla conserva issuer, authorization_endpoint y todos los certificados, y estampa metadata_failed_at', function (): void {
    [$tenant, $admin] = provisionCoreTenant('saml-326');

    $provider = createActiveSamlProvider($tenant->slug, $admin);

    $before = app(TenantContext::class)->runFor($tenant->id, function () use ($provider) {
        return DB::table('identity_providers')->where('public_id', $provider['public_id'])->first();
    });

    // Config al vuelo: apunta metadata_url a una URL que ahora falla
    // (destino privado, guarda 2), sin volver a validar el alta —
    // directamente sobre la fila ya creada, mismo patrón que CA-AUTH-326
    // de 1.4b para discovery_url.
    app(TenantContext::class)->runFor($tenant->id, function () use ($provider): void {
        $identityProviderId = DB::table('identity_providers')->where('public_id', $provider['public_id'])->value('id');
        DB::table('saml_identity_provider_settings')
            ->where('identity_provider_id', $identityProviderId)
            ->update(['metadata_url' => 'https://127.0.0.1/no-existe']);
    });

    config(['auth-local.saml.allow_insecure_metadata' => false]);

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}/metadata-refreshes"))
        ->assertStatus(422);

    config(['auth-local.saml.allow_insecure_metadata' => true]);

    app(TenantContext::class)->runFor($tenant->id, function () use ($provider, $before): void {
        $after = DB::table('identity_providers')->where('public_id', $provider['public_id'])->first();
        expect($after->issuer)->toBe($before->issuer)
            ->and($after->authorization_endpoint)->toBe($before->authorization_endpoint);

        $settings = DB::table('saml_identity_provider_settings')->where('identity_provider_id', $after->id)->first();
        expect($settings->metadata_failed_at)->not->toBeNull();

        expect(DB::table('identity_provider_certificates')->where('identity_provider_id', $after->id)->count())->toBe(1);
    });
});
