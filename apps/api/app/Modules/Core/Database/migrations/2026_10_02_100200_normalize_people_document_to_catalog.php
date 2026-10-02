<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CORE-003, paso 1.9c, RN-CORE-90 a 93 (funcional.md §14.6.4.4,
 * entrega N, OPEN-CORE-52 = A): migración **de datos** que lleva
 * `people.document_type` y `people.document_number` a su forma canónica
 * del catálogo cerrado (`App\Modules\Core\Domain\DocumentType`: `dni`,
 * `nie`, `pasaporte`). Sin `CHECK`: eso es la entrega siguiente (N+1), que
 * solo puede desplegarse cuando ya no corra ningún proceso de N-1.
 * Compatible con N-1: la versión anterior acepta cualquier texto, también
 * el canónico.
 *
 * - Tipo: `lower(trim(...))` si coincide con un código del catálogo
 *   (`DNI`, `dni`, ` Nie `... pasan al código canónico).
 * - Número: `upper(trim(...))`; en `dni` y `nie`, además sin espacios ni
 *   guiones intermedios (RN-CORE-92).
 *
 * Antes de escribir **comprueba** que (1) ninguna fila queda con un tipo
 * sin correspondencia y (2) la normalización no deja a dos personas vivas
 * del mismo centro con el mismo documento. Si ocurre cualquiera, **aborta
 * enumerando los `public_id` afectados y no toca nada**: nunca se inventa
 * una correspondencia (no hay producción; los datos de desarrollo se
 * pueden corregir o resembrar).
 *
 * La lista de códigos y la regla de normalización se repiten aquí a
 * propósito: una migración no debe depender de código de aplicación que
 * puede cambiar después (un tipo nuevo del enumerado no debe alterar lo
 * que esta migración hizo en su día).
 *
 * Conexión: `pgsql_platform` (BYPASSRLS, ADR-033 §5). Es una migración de
 * datos **entre todos los centros** y `plataforma_owner` queda sujeto a RLS
 * por `FORCE` (no vería ninguna fila de ningún tenant); `plataforma_platform`
 * tiene DML sobre `people` y es la única conexión que las ve todas.
 *
 * El `UPDATE` no pasa por los *observers* de auditoría (INV-003): es una
 * migración de datos del esquema, no una modificación hecha por una
 * persona. `updated_at` no se toca.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const CATALOG = ['dni', 'nie', 'pasaporte'];

    public function up(): void
    {
        $this->normalize(DB::connection('pgsql_platform'));
    }

    /**
     * @param  ?list<int>  $tenantIds  `null` = todos los centros (el caso de `up()`); acotar es solo para las pruebas
     */
    public function normalize(ConnectionInterface $platform, ?array $tenantIds = null): void
    {
        $platform->transaction(function () use ($platform, $tenantIds): void {
            $rows = $platform->table('people')
                ->where(fn ($query) => $query->whereNotNull('document_type')->orWhereNotNull('document_number'))
                ->when($tenantIds !== null, fn ($query) => $query->whereIn('tenant_id', $tenantIds))
                ->orderBy('id')
                ->get(['id', 'public_id', 'tenant_id', 'document_type', 'document_number', 'deleted_at']);

            $unknown = [];
            $changes = [];
            $seen = [];
            $duplicates = [];

            foreach ($rows as $row) {
                $type = $row->document_type === null ? null : strtolower(trim($row->document_type));

                if ($type !== null && ! in_array($type, self::CATALOG, true)) {
                    $unknown[] = $row->public_id;

                    continue;
                }

                $number = $row->document_number === null ? null : strtoupper(trim($row->document_number));

                if ($number !== null && in_array($type, ['dni', 'nie'], true)) {
                    $number = (string) preg_replace('/[\s-]+/', '', $number);
                }

                if ($type !== null && $number !== null && $row->deleted_at === null) {
                    $key = $row->tenant_id.'|'.$type.'|'.$number;

                    if (isset($seen[$key])) {
                        $duplicates[$key][] = $row->public_id;
                        $duplicates[$key][] = $seen[$key];
                    }

                    $seen[$key] = $row->public_id;
                }

                if ($type !== $row->document_type || $number !== $row->document_number) {
                    $changes[] = ['id' => $row->id, 'type' => $type, 'number' => $number];
                }
            }

            if ($unknown !== [] || $duplicates !== []) {
                $duplicated = array_values(array_unique(array_merge([], ...array_values($duplicates))));

                throw new RuntimeException(
                    'Migración de documentos abortada sin modificar ninguna fila. '
                    .'Tipos sin correspondencia en el catálogo (public_id): ['.implode(', ', $unknown).']. '
                    .'Personas vivas que la normalización convertiría en duplicadas (public_id): ['.implode(', ', $duplicated).'].'
                );
            }

            foreach ($changes as $change) {
                $platform->table('people')->where('id', $change['id'])->update([
                    'document_type' => $change['type'],
                    'document_number' => $change['number'],
                ]);
            }
        });
    }

    public function down(): void
    {
        // Sin reversión: la forma canónica es válida también para la versión
        // anterior (acepta cualquier texto) y la grafía original no se
        // conserva. No hay nada que deshacer en el esquema.
    }
};
