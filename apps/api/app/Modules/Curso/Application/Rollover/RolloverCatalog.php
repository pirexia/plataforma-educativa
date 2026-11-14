<?php

namespace App\Modules\Curso\Application\Rollover;

use App\Modules\Curso\Application\ModuleScopedChecks;
use App\Modules\Curso\Domain\AcademicYearRolloverElement;
use App\Modules\Curso\Domain\AcademicYearRolloverParticipant;
use App\Modules\Curso\Domain\AcademicYearRolloverRegistry;
use LogicException;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-62` (1.12b): el catálogo de elementos que
 * ofrecen los participantes registrados, **solo de módulos utilizables en el
 * centro** (`ModuleAvailability::isEnabled()` a través de `ModuleScopedChecks`,
 * `RN-CURSO-52`, `-60`): un elemento de un módulo no utilizable no se ofrece, no se
 * acepta y no se ejecuta.
 *
 * Orden: los participantes en un orden compatible con el grafo de `requires`
 * (un módulo cuyos elementos requieren los de otro va después), estable respecto al
 * orden de registro; dentro de cada módulo, el que declare el participante.
 */
final class RolloverCatalog
{
    public function __construct(
        private readonly AcademicYearRolloverRegistry $registry,
        private readonly ModuleScopedChecks $scoped,
    ) {}

    /**
     * Participantes de módulos utilizables, en orden de copia.
     *
     * @return list<AcademicYearRolloverParticipant>
     */
    public function participants(): array
    {
        return self::order($this->scoped->enabled($this->registry->all()));
    }

    /**
     * Elementos ofrecidos, por código y en orden de copia.
     *
     * @return array<string, AcademicYearRolloverElement>
     */
    public function elements(): array
    {
        $elements = [];

        foreach ($this->participants() as $participant) {
            foreach ($participant->elements() as $element) {
                $elements[$element->code] = $element;
            }
        }

        return $elements;
    }

    public function participantFor(string $moduleCode): ?AcademicYearRolloverParticipant
    {
        foreach ($this->participants() as $participant) {
            if ($participant->moduleCode() === $moduleCode) {
                return $participant;
            }
        }

        return null;
    }

    /**
     * Comprobación del registro **completo** (todos los participantes registrados, no
     * solo los utilizables): `code` con forma `<módulo>.<nombre>` cuyo prefijo es el
     * del participante, sin códigos duplicados, `requires` que apuntan a elementos
     * existentes y grafo acíclico (`RN-CURSO-62`, `CA-CURSO-123`). Un incumplimiento es
     * un error de programación: lo detecta un test sobre el registro real.
     *
     * @throws LogicException
     */
    public function assertConsistent(): void
    {
        $seen = [];

        foreach ($this->registry->all() as $participant) {
            foreach ($participant->elements() as $element) {
                if (! str_starts_with($element->code, $participant->moduleCode().'.') || $element->moduleCode() !== $participant->moduleCode()) {
                    throw new LogicException("El elemento «{$element->code}» no tiene la forma <módulo>.<nombre> de su módulo «{$participant->moduleCode()}» (RN-CURSO-62).");
                }

                if (isset($seen[$element->code])) {
                    throw new LogicException("Código de elemento duplicado en el registro: «{$element->code}» (RN-CURSO-62).");
                }

                $seen[$element->code] = $element;
            }
        }

        foreach ($seen as $element) {
            foreach ($element->requires as $required) {
                if (! isset($seen[$required])) {
                    throw new LogicException("El elemento «{$element->code}» requiere «{$required}», que no existe en el registro (RN-CURSO-62).");
                }
            }
        }

        // Ciclos entre elementos (búsqueda en profundidad con marcas).
        $state = [];
        $visit = function (string $code, array $path) use (&$visit, &$state, $seen): void {
            if (($state[$code] ?? null) === 2) {
                return;
            }

            if (($state[$code] ?? null) === 1) {
                throw new LogicException('Ciclo en el grafo de «requires» de los elementos: '.implode(' → ', [...$path, $code]).' (RN-CURSO-62).');
            }

            $state[$code] = 1;

            foreach ($seen[$code]->requires as $required) {
                $visit($required, [...$path, $code]);
            }

            $state[$code] = 2;
        };

        foreach (array_keys($seen) as $code) {
            $visit($code, []);
        }

        // Ciclo entre módulos (el orden de invocación no existiría).
        self::order($this->registry->all());
    }

    /**
     * Orden topológico estable de los participantes: el módulo A va antes que el B si algún
     * elemento de B requiere alguno de A.
     *
     * @param  list<AcademicYearRolloverParticipant>  $participants
     * @return list<AcademicYearRolloverParticipant>
     */
    public static function order(array $participants): array
    {
        $moduleOf = [];

        foreach ($participants as $participant) {
            foreach ($participant->elements() as $element) {
                $moduleOf[$element->code] = $participant->moduleCode();
            }
        }

        /** @var array<string, list<string>> $after módulo => módulos que deben ir antes */
        $after = [];

        foreach ($participants as $participant) {
            $after[$participant->moduleCode()] ??= [];

            foreach ($participant->elements() as $element) {
                foreach ($element->requires as $required) {
                    $dependency = $moduleOf[$required] ?? null;

                    if ($dependency !== null && $dependency !== $participant->moduleCode()) {
                        $after[$participant->moduleCode()][] = $dependency;
                    }
                }
            }
        }

        $ordered = [];
        $placed = [];
        $remaining = $participants;

        while ($remaining !== []) {
            $progress = false;

            foreach ($remaining as $index => $participant) {
                $pending = array_diff(array_unique($after[$participant->moduleCode()] ?? []), array_keys($placed));

                // Solo cuentan los módulos presentes: uno ausente (no utilizable) no bloquea.
                $pending = array_filter($pending, static fn (string $module): bool => array_key_exists($module, $after));

                if ($pending === []) {
                    $ordered[] = $participant;
                    $placed[$participant->moduleCode()] = true;
                    unset($remaining[$index]);
                    $progress = true;

                    break;
                }
            }

            if (! $progress) {
                throw new LogicException('Ciclo entre módulos en el grafo de «requires» de los elementos de la apertura del curso (RN-CURSO-62).');
            }

            $remaining = array_values($remaining);
        }

        return $ordered;
    }
}
