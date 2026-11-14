<?php

namespace Tests\Support;

use App\Support\Tenancy\TenantMigration;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-CURSO/funcional.md §1.3, datos.md §1.3 (1.10): tabla sonda de test con
 * `academic_year_id NOT NULL`, creada con el ayudante real
 * (`TenantMigration::tenantTable()` + `tenantForeignId()`), que por tanto
 * recibe el disparador `academic_year_write_guard` (ADR-057 §5.3). Es el
 * único «recurso por curso» que existe en 1.10 y el que permite cumplir
 * `INV-015` sin inventar uno de negocio. Precedente: `tenant_model_probes`
 * (bug 4 de 0.8: el *fixture* se crea con el ayudante, no con
 * `Schema::create` a mano).
 *
 * Se crea una sola vez y persiste entre tests y corridas (como
 * `tenant_model_probes`): borrarla por otra conexión mientras la
 * transacción del test sigue abierta provoca un bloqueo real (bug 3 de 0.7).
 * Nunca existe en producción: solo la crea la suite.
 */
final class AcademicYearProbe extends TenantModel
{
    public const TABLE = 'academic_year_probes';

    protected $table = self::TABLE;

    protected $fillable = ['name', 'academic_year_id'];

    /** Mismas filas del mismo centro: la relación que usa `CA-CURSO-040` («actualización por relación»). */
    public function siblings(): HasMany
    {
        return $this->hasMany(self::class, 'academic_year_id', 'academic_year_id');
    }

    public static function ensureTable(): void
    {
        if (Schema::connection('pgsql_owner')->hasTable(self::TABLE)) {
            return;
        }

        // `guardAcademicYearWrites()` exige transacción de `pgsql_owner` (como las
        // migraciones de Laravel): se crea y confirma en una propia.
        DB::connection('pgsql_owner')->transaction(function (): void {
            TenantMigration::tenantTable(self::TABLE, function (Blueprint $table): void {
                $table->text('name');
                TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            });
        });
    }
}
