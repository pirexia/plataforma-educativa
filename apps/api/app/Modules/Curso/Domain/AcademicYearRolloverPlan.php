<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-61` (1.12b): lo que `curso` pasa a un
 * participante al simular o copiar. Origen y destino son resúmenes (el
 * destino, el último leído bajo el `FOR SHARE` de la ejecución, `RN-CURSO-72`);
 * `elements` son los códigos de la selección efectiva **de ese módulo**;
 * `dateOffsetDays`, el desplazamiento de fechas (`destino.starts_on −
 * origen.starts_on`); `rolloverId`, el identificador interno de la copia
 * (`null` en la simulación).
 */
final readonly class AcademicYearRolloverPlan
{
    /**
     * @param  list<string>  $elements
     */
    public function __construct(
        public AcademicYearSummary $source,
        public AcademicYearSummary $target,
        public array $elements,
        public int $dateOffsetDays,
        public ?int $rolloverId = null,
    ) {}

    public function includes(string $elementCode): bool
    {
        return in_array($elementCode, $this->elements, true);
    }
}
