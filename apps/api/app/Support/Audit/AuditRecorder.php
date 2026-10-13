<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Http\RequestId;
use App\Support\Tenancy\PlatformAccessPurpose;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * ADR-035 §4/§9: orquesta la escritura de una fila en `audit_logs` desde
 * el ciclo de vida del ORM (RecordsAuditTrail). `changes` es NULL cuando
 * no hay diff que registrar (p. ej. delete/restore sin deleted_at
 * resoluble); nunca se llama fuera de un contexto de tenant activo.
 */
final class AuditRecorder
{
    /** Niveles máximos de arrays en `context` (el propio `context` cuenta como el primero). */
    private const MAX_CONTEXT_DEPTH = 3;

    /** Claves de mapa del `context` (ADR-035 §5): identificadores técnicos, nunca texto ni nombres propios. */
    private const CONTEXT_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** Valores string del `context`: un código (`rectificacion_calificacion`, `a.b-c`)... */
    private const CONTEXT_CODE_PATTERN = '/^[a-z0-9_.-]+$/';

    /** ... o un ULID (`public_id`, ADR-029). */
    private const CONTEXT_ULID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public function __construct(
        private readonly AuditChangeBuilder $changeBuilder,
    ) {}

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $rawChanges
     * @param  array<string, mixed>  $context  ADR-035 §5: solo identificadores y códigos, nunca valores de atributos ni texto libre. Las claves de los mapas cumplen `^[a-z][a-z0-9_]*$` (las listas indexadas se admiten); los valores string son un código `^[a-z0-9_.-]+$` o un ULID `^[0-9A-HJKMNP-TV-Z]{26}$`; int, bool y null se admiten. Hasta 3 niveles y mismo tope que `changes` (config audit.max_value_length). Vacío ⇒ `context` NULL.
     *
     * @throws InvalidArgumentException si `context` no cumple ADR-035 §5. Se lanza dentro de la transacción de negocio en curso (el registro se invoca desde los eventos del ORM) y la revierte: un `context` inválido aborta la operación de negocio completa, no solo el rastro.
     */
    public function record(Model&Auditable $model, string $event, array $rawChanges = [], array $context = []): void
    {
        $this->assertContextIsAllowed($context);

        $tenantContext = app(TenantContext::class);

        if ($tenantContext->isPlatformMode()) {
            // ADR-046 §6.5. Con propósito Mantenimiento, sin cambios:
            // defensa en profundidad, no redundancia a retirar — este
            // mecanismo es para audit_logs (tabla de tenant), y un
            // mantenimiento sin sujeto no debería tocar ningún modelo
            // Auditable de tenant.
            //
            // Con un propósito de backoffice, retorna en silencio: el
            // rastro de esa escritura es admin_action_logs, y su
            // obligación la garantiza la regla de cierre de
            // runAsPlatform() (TenantContext::platformPurpose() ===
            // BackofficeEscritura ⇒ after() exige al menos una entrada),
            // no este método. ADR-045 obliga al backoffice a escribir
            // module_subscriptions (Auditable, tabla de tenant) fijando
            // tenant_id a mano dentro del bloque de plataforma; sin esta
            // rama, esa escritura seguiría lanzando aquí.
            if ($tenantContext->platformPurpose() === PlatformAccessPurpose::Mantenimiento) {
                throw new RuntimeException(
                    'AuditRecorder no escribe en modo plataforma con propósito Mantenimiento '.
                    '(TenantContext::runAsPlatform()); el rastro de esa operación corresponde '.
                    'a admin_action_logs, no a audit_logs.'
                );
            }

            return;
        }

        if (! $tenantContext->hasTenant()) {
            // audit_logs es tabla de tenant (ADR-034 §3): sin contexto no
            // hay a qué tenant_id escribir. No ocurre hoy con los 5
            // modelos del núcleo (todos exigen tenant activo para
            // guardarse a sí mismos), documentado por si un futuro modelo
            // Auditable se creara alguna vez fuera de contexto.
            return;
        }

        $alias = Relation::getMorphAlias($model::class);

        if ($alias === $model::class) {
            throw new RuntimeException(
                $model::class.' no está en el morph map (ADR-034 §3): no se puede auditar con un alias estable.'
            );
        }

        AuditLog::create([
            'occurred_at' => now(),
            'actor_user_id' => AuditActor::resolveUserId(),
            'actor_type' => AuditActor::resolveType(),
            'auditable_type' => $alias,
            'auditable_id' => $model->getKey(),
            'auditable_public_id' => $model->public_id ?? null,
            'event' => $event,
            'changes' => $rawChanges === [] ? null : $this->changeBuilder->build($model, $rawChanges),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_id' => app(RequestId::class)->current(),
            'context' => $context === [] ? null : $context,
        ]);
    }

    /**
     * ADR-035 §5: `context` transporta identificadores y códigos, nunca
     * valores de atributos. No se puede decidir por semántica qué es un
     * valor de atributo, pero sí imponer su forma: claves de mapa
     * `^[a-z][a-z0-9_]*$` (las de listas indexadas, enteras, se admiten),
     * valores string que sean un código `^[a-z0-9_.-]+$` o un ULID, int,
     * bool y null, hasta 3 niveles y el mismo tope de tamaño que
     * `changes`. Texto libre, nombres propios y correos no pasan ese
     * patrón. Se lanza en vez de truncar o redactar: un `context`
     * que no cumple es un error del llamador, y truncar dejaría un rastro
     * de auditoría engañoso (ADR-035 §5: «o entero o nada»). Se valida antes
     * de cualquier otra comprobación para que el fallo no dependa del
     * modo de tenant.
     *
     * @param  array<array-key, mixed>  $context
     */
    private function assertContextIsAllowed(array $context): void
    {
        if ($context === []) {
            return;
        }

        $check = function (mixed $value, int $depth) use (&$check): void {
            if (is_array($value)) {
                if ($depth > self::MAX_CONTEXT_DEPTH) {
                    throw new InvalidArgumentException(
                        'El context de auditoría admite como máximo '.self::MAX_CONTEXT_DEPTH.' niveles de anidamiento (ADR-035 §5).'
                    );
                }

                foreach ($value as $key => $item) {
                    if (is_string($key) && preg_match(self::CONTEXT_KEY_PATTERN, $key) !== 1) {
                        throw new InvalidArgumentException(
                            'Las claves del context de auditoría deben cumplir '.self::CONTEXT_KEY_PATTERN.' (ADR-035 §5).'
                        );
                    }

                    $check($item, $depth + 1);
                }

                return;
            }

            if (is_string($value)
                && preg_match(self::CONTEXT_CODE_PATTERN, $value) !== 1
                && preg_match(self::CONTEXT_ULID_PATTERN, $value) !== 1) {
                throw new InvalidArgumentException(
                    'Los valores string del context de auditoría deben ser un código ('.self::CONTEXT_CODE_PATTERN.') o un ULID, nunca texto libre (ADR-035 §5).'
                );
            }

            if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_bool($value)) {
                throw new InvalidArgumentException(
                    'El context de auditoría solo admite escalares (string, int, bool, null) anidados en arrays (ADR-035 §5).'
                );
            }
        };
        $check($context, 1);

        try {
            $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                'El context de auditoría no es serializable a JSON (p. ej. UTF-8 inválido) (ADR-035 §5).',
                0,
                $e,
            );
        }

        if (mb_strlen($encoded) > (int) config('audit.max_value_length')) {
            throw new InvalidArgumentException(
                'El context de auditoría supera el tope audit.max_value_length (ADR-035 §5): transporta identificadores y códigos, no texto.'
            );
        }
    }
}
