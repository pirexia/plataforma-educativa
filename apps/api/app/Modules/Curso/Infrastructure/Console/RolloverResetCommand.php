<?php

namespace App\Modules\Curso\Infrastructure\Console;

use App\Modules\Curso\Application\Rollover\RolloverCancellation;
use App\Modules\Curso\Domain\Models\YearRollover;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditActor;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * REQ-CURSO/operacion.md §4.1, §7 (1.12b, `RN-CURSO-85`, `CA-CURSO-157`): para el operador de la plataforma,
 * cancela una copia huérfana cuando el centro no puede hacerlo (p. ej. ningún usuario con
 * `apertura_curso_academico.eliminar`). Aplica la **misma regla** que `POST /academic-year-rollovers/{id}/cancel`
 * (`RolloverCancellation`): solo copias en `pendiente`, `en_curso` o `revirtiendo`, y solo pasados 30 minutos
 * desde su último cambio. `--force` salta la caducidad, pide confirmación y deja un aviso en el registro de la
 * aplicación. Auditado como `updated` **sin actor** (proceso de consola).
 *
 * Una copia de otro centro no se encuentra: la consulta va acotada al centro de `--tenant` (`INV-001`).
 * Idempotente: ejecutarlo dos veces no cambia nada la segunda (la copia ya no está en vuelo).
 */
class RolloverResetCommand extends Command
{
    protected $signature = 'curso:rollover-reset
        {public_id : Identificador público de la copia}
        {--tenant= : Slug del centro al que pertenece la copia (obligatorio)}
        {--force : Salta la caducidad de 30 minutos, tras confirmación}';

    protected $description = 'Cancela una copia huérfana del rollover del curso (REQ-CURSO/operacion.md §7, RN-CURSO-85)';

    public function handle(RolloverCancellation $cancellation, TenantContext $context): int
    {
        $slug = $this->option('tenant');

        if (! is_string($slug) || $slug === '') {
            $this->error('--tenant es obligatorio: indica el slug del centro.');

            return self::FAILURE;
        }

        $tenant = Tenant::findBySlug($slug);

        if ($tenant === null) {
            $this->error("No existe ningún tenant con el slug «{$slug}».");

            return self::FAILURE;
        }

        $publicId = (string) $this->argument('public_id');
        $force = (bool) $this->option('force');

        return $context->runFor($tenant->id, function () use ($cancellation, $tenant, $publicId, $force): int {
            if (! YearRollover::query()->where('public_id', $publicId)->exists()) {
                $this->error("El centro «{$tenant->slug}» no tiene ninguna copia con el identificador «{$publicId}».");

                return self::FAILURE;
            }

            if ($force) {
                if (! $this->confirm('--force salta la caducidad de 30 minutos: si el trabajo sigue vivo en la cola, la copia se marcará como fallida igualmente. ¿Continuar?', false)) {
                    $this->warn('Cancelado por el operador: no se ha hecho nada.');

                    return self::FAILURE;
                }

                Log::warning('curso.rollover.reset.forced', ['tenant' => $tenant->slug, 'rollover' => $publicId]);
            }

            try {
                $rollover = AuditActor::actingAs('console', fn (): YearRollover => $cancellation->cancel($publicId, null, $force));
            } catch (ApiException $e) {
                $detail = $e->detailKey !== null ? (string) __($e->detailKey, $e->detailParams) : $e->getMessage();
                $this->error($detail);

                return self::FAILURE;
            }

            $this->info("Copia {$publicId} cancelada: estado {$rollover->status->value}.");

            return self::SUCCESS;
        });
    }
}
