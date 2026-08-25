<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
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

        static::restoring(function (Model $model): void {
            self::rememberDeletedAtBefore($model, $model->getOriginal('deleted_at'));
        });

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
