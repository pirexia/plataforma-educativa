<?php

namespace App\Modules\Core\Domain;

use App\Models\User;
use App\Modules\Core\Domain\Models\UserImport;
use Illuminate\Http\UploadedFile;

/**
 * funcional.md §1.10, §7: destino de importación de personal para
 * `REQ-ONB-002` (1.24), de modo que ese módulo consuma la importación de
 * usuarios de 1.1 en vez de reimplementarla (`INV-007`).
 *
 * Sube y valida (fase 1 de funcional.md §4.4): la ejecución (fase 2,
 * `POST /user-imports/{id}/execute`) es un paso deliberadamente separado
 * y con idempotencia propia, no parte de este contrato.
 */
interface BulkUserImporter
{
    public function import(UploadedFile $file, bool $sendInvitations, User $requestedBy): UserImport;
}
