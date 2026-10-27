<?php

namespace App\Modules\Core\Domain;

/**
 * REQ-FAM-UNIT/api.md §4 (1.14-b, `OPEN-FAM-UNIT-11`), `ADR-054`, `INV-007`: ciclo de vida de una exportación para el
 * trabajo en cola de **otro módulo** que genera el artefacto de un `kind` suyo. Complementa a `ExportRequestService`
 * (que crea la solicitud): `Core` posee la tabla `data_exports` y sus estados, y el módulo dueño de los datos los
 * recorre por esta interfaz sin importar el modelo (precedente: `1.9b` añadió el `kind` `users` con su trabajo dentro de
 * `Core`; un `kind` cuyos datos no son de `Core` no puede leerlos desde aquí).
 *
 * Las tres operaciones son del centro en curso (el contexto de tenant del trabajo lo gestiona el middleware de colas).
 */
interface ExportJobLifecycle
{
    /**
     * Pasa la solicitud a `generando` y devuelve lo que el trabajo necesita. `null` si la solicitud ya no existe o su
     * solicitante se borró entre la solicitud y la ejecución (denegar por defecto, `INV-002`): en este último caso la
     * deja `fallida`.
     */
    public function start(int $dataExportId): ?ExportJobContext;

    /** Deja la solicitud `completada` con el artefacto ya guardado en el almacenamiento (`object_key`). */
    public function complete(int $dataExportId, string $objectKey, int $rowCount): void;

    /** Deja la solicitud `fallida` con un motivo traducible por el cliente (`core.export.generation_failed` por defecto). */
    public function fail(int $dataExportId, string $errorCode = 'core.export.generation_failed'): void;
}
