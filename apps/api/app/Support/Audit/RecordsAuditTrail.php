<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use WeakMap;

/**
 * ADR-035 §4/§9 (0.9.c): engancha created/updated/deleted/restored y
 * delega la escritura en AuditRecorder. Un modelo con este trait debe
 * implementar Auditable (verificado por el test de arquitectura, no por
 * PHP: un trait no puede exigir una interfaz al usuario).
 *
 * Soft delete dispara su propio ciclo save() interno (SoftDeletes::
 * runSoftDelete()/restore()), que también hace "updated" con
 * deleted_at + updated_at/updated_by dirty. Registrar ese "updated" además
 * del "deleted"/"restored" duplicaría la fila — se suprime aquí y lo
 * registran los hooks deleted/restored, con el valor previo de deleted_at
 * capturado justo antes de que SoftDeletes lo toque.
 *
 * Hallazgo propio (1.3, `MfaReset`, el primer `Auditable` que es
 * `AppendOnlyModel` en vez de `TenantModel`): `restoring`/`restored` **no**
 * son métodos estáticos reales de `Illuminate\Database\Eloquent\Model` —
 * solo existen cuando el modelo usa `SoftDeletes`, que los añade. Sin
 * `SoftDeletes`, `static::restoring(...)` cae en `Model::__callStatic()`,
 * que hace `(new static)->restoring(...)` para resolverlo — y esa
 * instanciación reentra en `bootIfNotBooted()` de la MISMA clase mientras
 * todavía se está *booteando*, lo que Laravel rechaza con
 * `LogicException` desde Laravel 13. Por eso los dos enganches de abajo
 * se condicionan a que el modelo use `SoftDeletes` — un `AppendOnlyModel`
 * nunca tiene `deleted_at` que restaurar, así que omitirlos no pierde
 * ninguna auditoría real: `AppendOnlyModel::booted()` ya impide `update`/
 * `delete` en el motor (`REVOKE`) y en PHP (`LogicException` propia).
 */
trait RecordsAuditTrail
{
    /** @var WeakMap<Model, mixed>|null */
    private static ?WeakMap $auditDeletedAtBefore = null;

    protected static function bootRecordsAuditTrail(): void
    {
        static::created(function (Model&Auditable $model): void {
            // ADR-040 §4.2: el filtro va aquí, en el enganche automático, y
            // nunca dentro de AuditRecorder — ese también es el camino de
            // la escritura manual de ADR-039 §4.5 (login/logout/
            // password_reset_requested), y una llamada explícita no puede
            // desaparecer en silencio por una declaración de otro modelo.
            if (in_array('created', $model->auditExcludedEvents(), true)) {
                return;
            }

            app(AuditRecorder::class)->record($model, 'created', self::auditDirtyAsRawChanges($model));
        });

        static::updated(function (Model&Auditable $model): void {
            $dirty = self::auditDirtyAsRawChanges($model);

            if ($dirty === []) {
                return;
            }

            $businessKeys = array_diff(array_keys($dirty), ['updated_at', 'updated_by']);

            if ($businessKeys === [] || $businessKeys === ['deleted_at']) {
                return;
            }

            app(AuditRecorder::class)->record($model, 'updated', $dirty);
        });

        static::deleting(function (Model $model): void {
            self::rememberDeletedAtBefore($model, $model->getOriginal('deleted_at'));
        });

        static::deleted(function (Model&Auditable $model): void {
            app(AuditRecorder::class)->record($model, 'deleted', [
                'deleted_at' => [self::recallDeletedAtBefore($model), $model->getAttribute('deleted_at')],
            ]);
        });

        if (! in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            return;
        }

        // Larastan analiza este trait una vez por cada modelo que lo usa,
        // incluidos los que (como MfaReset) NO tienen restoring()/restored()
        // — el guard de arriba ya lo hace inalcanzable en tiempo de
        // ejecución para esos, pero el análisis estático no puede probarlo.
        // @phpstan-ignore staticMethod.notFound
        static::restoring(function (Model $model): void {
            self::rememberDeletedAtBefore($model, $model->getOriginal('deleted_at'));
        });

        // @phpstan-ignore staticMethod.notFound
        static::restored(function (Model&Auditable $model): void {
            app(AuditRecorder::class)->record($model, 'restored', [
                'deleted_at' => [self::recallDeletedAtBefore($model), null],
            ]);
        });
    }

    /**
     * id/tenant_id/public_id no son "cambios" en el sentido de este
     * mecanismo: id y tenant_id ya están en auditable_id y en el
     * tenant_id de la propia fila de audit_logs; public_id está en
     * auditable_public_id (ADR-034 §3). Incluirlos aquí solo añadiría
     * ruido "redacted: identifier" sin ninguna información nueva.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private static function auditDirtyAsRawChanges(Model $model): array
    {
        $structural = ['id', 'tenant_id', 'public_id'];
        $raw = [];

        foreach ($model->getDirty() as $attribute => $to) {
            if (in_array($attribute, $structural, true)) {
                continue;
            }

            $raw[$attribute] = [$model->getOriginal($attribute), $to];
        }

        return $raw;
    }

    private static function rememberDeletedAtBefore(Model $model, mixed $value): void
    {
        self::$auditDeletedAtBefore ??= new WeakMap;
        self::$auditDeletedAtBefore[$model] = $value;
    }

    private static function recallDeletedAtBefore(Model $model): mixed
    {
        return self::$auditDeletedAtBefore[$model] ?? null;
    }
}
