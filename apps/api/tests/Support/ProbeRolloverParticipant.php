<?php

namespace Tests\Support;

use App\Modules\Curso\Domain\AcademicYearRolloverElement;
use App\Modules\Curso\Domain\AcademicYearRolloverElementReport;
use App\Modules\Curso\Domain\AcademicYearRolloverElementResult;
use App\Modules\Curso\Domain\AcademicYearRolloverIssue;
use App\Modules\Curso\Domain\AcademicYearRolloverItem;
use App\Modules\Curso\Domain\AcademicYearRolloverParticipant;
use App\Modules\Curso\Domain\AcademicYearRolloverPlan;
use App\Modules\Curso\Domain\AcademicYearRolloverReport;
use App\Modules\Curso\Domain\AcademicYearRolloverResult;
use App\Modules\Curso\Domain\AcademicYearRolloverReversal;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * Participante de prueba de la apertura del curso siguiente (REQ-CURSO-002, `tests/Feature/Curso/`): registra lo
 * que recibe (simulaciones, copias, reversiones, y el usuario autenticado en cada llamada) y se puede configurar
 * para fallar o devolver incidencias. Cada elemento aporta una fila principal y dos *items* ficticios.
 */
final class ProbeRolloverParticipant implements AcademicYearRolloverParticipant
{
    /** @var list<list<string>> elementos de cada `preview()` */
    public array $previews = [];

    /** @var list<list<string>> elementos de cada `copy()` */
    public array $copies = [];

    /** @var list<int|string|null> usuario autenticado en cada `copy()` */
    public array $copyActors = [];

    /** @var list<int> número de items de cada `revert()` */
    public array $reverts = [];

    /** @var list<AcademicYearRolloverIssue> incidencias que devuelve `preview()` */
    public array $issues = [];

    /** @var list<AcademicYearRolloverIssue> incidencias que devuelve `checkRevert()` */
    public array $revertIssues = [];

    /** Se invoca dentro de `preview()` (p. ej. para esperar un bloqueo consultivo del test). */
    public ?Closure $onPreview = null;

    /** Se invoca dentro de `copy()` (p. ej. para lanzar una excepción o bloquear). */
    public ?Closure $onCopy = null;

    private static int $sequence = 0;

    /** @param  list<AcademicYearRolloverElement>  $elements */
    public function __construct(private readonly string $module, private readonly array $elements) {}

    /**
     * Tres elementos de `curso`: `alpha`, `beta` (requiere alpha) y `gamma` (paquete `teaching_assignments`, requiere beta).
     */
    public static function trio(string $module = 'curso'): self
    {
        return new self($module, [
            new AcademicYearRolloverElement("{$module}.alpha", 'curso.rollover.status.pendiente', [], 'curso_academico.crear'),
            new AcademicYearRolloverElement("{$module}.beta", 'curso.rollover.status.en_curso', ["{$module}.alpha"], 'curso_academico.leer'),
            new AcademicYearRolloverElement("{$module}.gamma", 'curso.rollover.status.completado', ["{$module}.beta"], 'curso_academico.actualizar', 'teaching_assignments'),
        ]);
    }

    public function moduleCode(): string
    {
        return $this->module;
    }

    public function elements(): array
    {
        return $this->elements;
    }

    public function preview(AcademicYearRolloverPlan $plan): AcademicYearRolloverReport
    {
        $this->previews[] = $plan->elements;

        if ($this->onPreview !== null) {
            ($this->onPreview)($plan);
        }

        return new AcademicYearRolloverReport(array_map(
            fn (string $code): AcademicYearRolloverElementReport => new AcademicYearRolloverElementReport($code, 1, issues: $this->issues),
            $plan->elements,
        ));
    }

    public function copy(AcademicYearRolloverPlan $plan): AcademicYearRolloverResult
    {
        $this->copies[] = $plan->elements;
        $this->copyActors[] = Auth::id();

        if ($this->onCopy !== null) {
            ($this->onCopy)($plan);
        }

        $items = [];
        $results = [];

        foreach ($plan->elements as $code) {
            $results[] = new AcademicYearRolloverElementResult($code, 1);
            $items[] = new AcademicYearRolloverItem('probe', 1, ++self::$sequence);
            $items[] = new AcademicYearRolloverItem('probe', 2, ++self::$sequence);
        }

        return new AcademicYearRolloverResult($results, $items);
    }

    public function checkRevert(AcademicYearRolloverReversal $reversal): array
    {
        return $this->revertIssues;
    }

    public function revert(AcademicYearRolloverReversal $reversal): void
    {
        $this->reverts[] = count($reversal->items);
    }
}
