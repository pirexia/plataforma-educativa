<?php

use App\Models\AuditLog;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantMigration;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// REQ-ACAD-001, 1.11-a. CA-ACAD-013 (ADR-062 §5.5, ADR-035 §4): desglose por
// idioma en `audit_logs.changes`, con un modelo Auditable de prueba (no hay
// tabla de acad hasta 1.11-b). INV-003, INV-015.

class TranslatableAuditProbe extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'audit_translatable_probes';

    protected $fillable = ['name_translations'];

    protected $casts = ['name_translations' => 'array'];

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }
}

beforeEach(function (): void {
    Relation::morphMap(['audit_translatable_probe' => TranslatableAuditProbe::class]);

    if (Schema::connection('pgsql_owner')->hasTable('audit_translatable_probes')) {
        return;
    }

    TenantMigration::tenantTable('audit_translatable_probes', function (Blueprint $table): void {
        $table->char('public_id', 26)->unique();
        TenantMigration::translatableColumn($table, 'name');
    });
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-ACAD-013 ADR-062 §5.5 INV-003: un cambio real de es-ES y un fr nuevo dejan exactamente dos entradas en audit_logs.changes', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $probe = TranslatableAuditProbe::create(['name_translations' => ['es-ES' => 'Lengua', 'en' => 'Language']]);

        $created = AuditLog::query()->where('auditable_id', $probe->id)->where('event', 'created')->first();

        expect($created->changes)->toHaveKeys(['name_translations.es-ES', 'name_translations.en'])
            ->and($created->changes)->not->toHaveKey('name_translations')
            ->and($created->changes['name_translations.es-ES'])->toEqual(['from' => null, 'to' => 'Lengua']);

        $probe->update(['name_translations' => ['es-ES' => 'Lengua Castellana', 'en' => 'Language', 'fr' => 'Langue castillane']]);

        $updated = AuditLog::query()->where('auditable_id', $probe->id)->where('event', 'updated')->first();

        expect(array_keys($updated->changes))->toEqualCanonicalizing(['name_translations.es-ES', 'name_translations.fr'])
            ->and($updated->changes['name_translations.es-ES'])->toEqual(['from' => 'Lengua', 'to' => 'Lengua Castellana'])
            ->and($updated->changes['name_translations.fr'])->toEqual(['from' => null, 'to' => 'Langue castillane']);
    });
});

test('CA-ACAD-013 ADR-062 §5.5: un valor por encima del tope de ADR-035 §5 se redacta oversized solo en su idioma', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $probe = TranslatableAuditProbe::create(['name_translations' => ['es-ES' => 'Lengua']]);
        $long = str_repeat('x', 300);

        $probe->update(['name_translations' => ['es-ES' => $long, 'en' => 'Language']]);

        $updated = AuditLog::query()->where('auditable_id', $probe->id)->where('event', 'updated')->first();

        expect($updated->changes['name_translations.es-ES']['redacted'])->toBe('oversized')
            ->and($updated->changes['name_translations.en'])->toEqual(['from' => null, 'to' => 'Language'])
            ->and(json_encode($updated->changes))->not->toContain($long);
    });
});
