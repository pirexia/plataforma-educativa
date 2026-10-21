<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-61` (1.12b, `REQ-CURSO-002`): participante
 * de la apertura del curso siguiente, que implementa cada módulo que ofrece
 * copiar datos y registra en `AcademicYearRolloverRegistry`. Contrato
 * **síncrono** en el módulo propietario (`ADR-048 §4.9`): `curso` necesita el
 * resultado y el fallo de cada módulo para confirmar o revertir toda la copia;
 * la asincronía la pone `curso` con su trabajo en cola.
 *
 * La clase vive en el módulo dueño, **lee y escribe solo sus tablas** y solo
 * importa `Curso\Domain` (`INV-007`, `AR-01`). Las fechas del curso las recibe
 * en el plan, nunca de `academic_years`. `curso` lo filtra por módulo
 * contratado (`RN-CURSO-62`).
 */
interface AcademicYearRolloverParticipant extends ModuleScopedContribution
{
    /**
     * Los elementos que ofrece, en el orden en que se copian dentro del módulo.
     *
     * @return list<AcademicYearRolloverElement>
     */
    public function elements(): array;

    /**
     * Simula la copia (solo lectura, sin bloqueos de fila, `RN-CURSO-65`).
     * Incluye las incidencias que impiden ejecutar (`RN-CURSO-66`); una
     * omisión no es incidencia.
     */
    public function preview(AcademicYearRolloverPlan $plan): AcademicYearRolloverReport;

    /**
     * Copia **sus** tablas al destino, por instancia a través de sus modelos
     * (`AR-15`, `RN-CURSO-79`), de padres a hijos. Dentro de la transacción de
     * `curso`; no abre la suya. No escribe nunca en el origen (`RN-CURSO-77`).
     */
    public function copy(AcademicYearRolloverPlan $plan): AcademicYearRolloverResult;

    /**
     * Incidencias que impiden revertir (datos añadidos después que dependen de
     * lo copiado, `RN-CURSO-74`). Solo lectura.
     *
     * @return list<AcademicYearRolloverIssue>
     */
    public function checkRevert(AcademicYearRolloverReversal $reversal): array;

    /**
     * Borra lógicamente, por instancia y de hijos a padres, las filas de sus
     * *items* que sigan vivas, aunque se hayan editado (`RN-CURSO-75`). Una
     * fila que ya no existe se trata como ya borrada.
     */
    public function revert(AcademicYearRolloverReversal $reversal): void;
}
