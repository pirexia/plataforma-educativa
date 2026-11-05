<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-004, docs/modulos/REQ-FAM-UNIT/datos.md §1.8, ADR-066 §5.5, ADR-062, issue #482, INV-015: el motor impide
// modificar título y cuerpo de una versión de texto legal que ya no es borrador, aunque el UPDATE no pase por el servicio.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Ejecuta `$work` dentro de una transacción con la conexión dada y el centro fijado; devuelve el SQLSTATE del error o
 * `null` si no falló. Siempre se deshace.
 */
function termsDirectUpdate(string $name, int $tenantId, Closure $work): ?string
{
    $connection = DB::connection($name);

    try {
        $connection->transaction(function () use ($connection, $tenantId, $work): void {
            $connection->selectOne("SELECT set_config('app.tenant_id', ?, true)", [(string) $tenantId]);
            $work($connection);

            throw new RuntimeException('deshacer');
        });
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? 'sin-sqlstate';
    } catch (RuntimeException) {
        return null;
    }

    return null;
}

test('REQ-FAM-UNIT-004 #482: el rol de la aplicación no puede cambiar título ni cuerpo de una versión publicada ni retirada (SQLSTATE 23001)', function (): void {
    [$tenant, $admin] = Fam::setup('tv-482a');
    $published = Fam::publishText($tenant, $admin, 'web');
    $id = Fam::row($tenant, 'terms_versions', $published)->id;

    // Solo la conexión de aplicación: la fila vive en la transacción del test y las otras sesiones no la ven. El disparador no
    // distingue de roles (un disparador de fila alcanza también al propietario), pero eso no se prueba aquí.
    foreach (['title_translations', 'body_translations'] as $column) {
        expect(termsDirectUpdate('pgsql', $tenant->id, fn ($c) => $c->table('terms_versions')->where('id', $id)->update([$column => json_encode(['es-ES' => 'manipulado'])])))
            ->toBe('23001', "la aplicación modificó {$column} de una versión publicada");
    }

    // Retirada (otra publicación retira la anterior): sigue inmutable.
    Fam::publishText($tenant, $admin, 'web');
    expect(Fam::row($tenant, 'terms_versions', $published)->status)->toBe('retirada')
        ->and(termsDirectUpdate('pgsql', $tenant->id, fn ($c) => $c->table('terms_versions')->where('id', $id)->update(['body_translations' => json_encode(['es-ES' => 'manipulado'])])))->toBe('23001');
});

test('REQ-FAM-UNIT-004 #482: el disparador no estorba la edición de un borrador, la publicación, la retirada ni el borrado lógico, y escribir el mismo texto no falla', function (): void {
    [$tenant, $admin] = Fam::setup('tv-482b');

    // Borrador: editable por la API y por UPDATE directo.
    $draft = Fam::draftText($tenant, $admin, 'web');
    Fam::call($tenant, $admin, 'patch', "/terms-versions/{$draft}", ['title_translations' => ['es-ES' => 'Nuevo'], 'body_translations' => ['es-ES' => 'Cuerpo nuevo']])->assertOk();
    $draftId = Fam::row($tenant, 'terms_versions', $draft)->id;
    expect(termsDirectUpdate('pgsql', $tenant->id, fn ($c) => $c->table('terms_versions')->where('id', $draftId)->update(['body_translations' => json_encode(['es-ES' => 'otro'])])))->toBeNull();

    // Publicar (borrador → publicada) y retirar (publicada → retirada) por el servicio siguen funcionando.
    Fam::call($tenant, $admin, 'post', "/terms-versions/{$draft}/publish")->assertOk();
    expect(Fam::row($tenant, 'terms_versions', $draft)->status)->toBe('publicada');
    $next = Fam::publishText($tenant, $admin, 'web');
    expect(Fam::row($tenant, 'terms_versions', $draft)->status)->toBe('retirada')
        ->and(Fam::row($tenant, 'terms_versions', $next)->status)->toBe('publicada');

    // Una versión publicada admite UPDATE que no toca título ni cuerpo (p. ej. el borrado lógico o la marca de tiempo).
    $nextId = Fam::row($tenant, 'terms_versions', $next)->id;
    expect(termsDirectUpdate('pgsql', $tenant->id, fn ($c) => $c->table('terms_versions')->where('id', $nextId)->update(['updated_at' => now()])))->toBeNull()
        ->and(termsDirectUpdate('pgsql', $tenant->id, fn ($c) => $c->table('terms_versions')->where('id', $nextId)->update(['title_translations' => DB::raw('title_translations')])))->toBeNull();
});

test('REQ-FAM-UNIT-004 #482: el rol de la aplicación no puede desactivar ni borrar el disparador', function (): void {
    [$tenant] = Fam::setup('tv-482c');

    foreach (['ALTER TABLE terms_versions DISABLE TRIGGER terms_versions_published_text_immutable', 'DROP TRIGGER terms_versions_published_text_immutable ON terms_versions'] as $change) {
        expect(termsDirectUpdate('pgsql', $tenant->id, fn ($c) => $c->statement($change)))->toBe('42501', $change);
    }

    $enabled = DB::connection('pgsql_owner')->selectOne("SELECT tgenabled FROM pg_trigger WHERE tgname = 'terms_versions_published_text_immutable'");
    expect($enabled->tgenabled)->toBe('O');
});
