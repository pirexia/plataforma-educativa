<?php

namespace App\Modules\Core\Application;

use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Domain\DocumentType;
use App\Modules\Core\Domain\TenantSettingsReader;
use Illuminate\Support\Collection;

/**
 * funcional.md §4.4 paso 3, api.md §7. Valida una fila ya partida en
 * columnas (INV-010). Reutilizado por `ValidateUserImport` (fase 1, no
 * escribe) y por `ExecuteUserImport` (fase 2, revalida antes de crear:
 * §6 "Importación ejecutada dos veces" exige que las filas ya creadas se
 * reporten como error, no como duplicado silencioso).
 *
 * `$seenEmails`/`$seenDocuments` son por referencia: el propio fichero
 * puede repetir un correo o un documento (CA-CORE-034), y solo quien
 * recorre el fichero entero sabe qué ha visto ya.
 */
final class UserImportRowValidator
{
    public function __construct(
        private readonly TenantSettingsReader $settings,
        private readonly DocumentNumberValidator $documentValidator,
    ) {}

    /**
     * @param  array<string, ?string>  $row
     * @param  array<string, bool>  $seenEmails
     * @param  array<string, bool>  $seenDocuments
     * @param  Collection<string, Role>  $rolesByCode
     * @return array{errors: list<array{line: int, column: string, code: string, message: string}>, roles: list<string>, document: array{type: ?string, number: ?string}}
     */
    public function validate(array $row, int $line, array &$seenEmails, array &$seenDocuments, Collection $rolesByCode): array
    {
        $errors = [];

        foreach (['email', 'given_name', 'family_name_1'] as $required) {
            if (($row[$required] ?? null) === null) {
                $errors[] = $this->error($line, $required, 'campo_obligatorio_vacio');
            }
        }

        $email = $row['email'] !== null ? strtolower($row['email']) : null;

        if ($email !== null) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = $this->error($line, 'email', 'formato_invalido');
            } elseif (isset($seenEmails[$email])) {
                $errors[] = $this->error($line, 'email', 'duplicado_en_fichero');
            } elseif (User::query()->where('email', $email)->exists()) {
                $errors[] = $this->error($line, 'email', 'duplicado_en_base_de_datos');
            }

            $seenEmails[$email] = true;
        }

        // RN-CORE-90 a 92 (§14.6.4.6): catálogo cerrado con grafía tolerante
        // en la hoja (OPEN-CORE-50 = A), par completo y valor normalizado.
        $document = ['type' => null, 'number' => null];
        $rawType = $row['document_type'] ?? null;
        $rawNumber = $row['document_number'] ?? null;

        if ($rawType !== null || $rawNumber !== null) {
            $type = DocumentType::fromLooseCode($rawType);

            if ($rawType !== null && $type === null) {
                $errors[] = $this->error($line, 'document_type', 'tipo_documento_no_valido');
            } elseif ($type === null || $rawNumber === null) {
                $errors[] = $this->error($line, $type === null ? 'document_type' : 'document_number', 'documento_incompleto');
            } elseif (! $this->documentValidator->isValid($type, $rawNumber)) {
                $errors[] = $this->error($line, 'document_number', 'formato_invalido');
            } else {
                $number = $this->documentValidator->normalize($type, $rawNumber);
                $documentKey = $type->value.'|'.$number;

                if (isset($seenDocuments[$documentKey])) {
                    $errors[] = $this->error($line, 'document_number', 'duplicado_en_fichero');
                } elseif (Person::query()->where('document_type', $type->value)->where('document_number', $number)->exists()) {
                    $errors[] = $this->error($line, 'document_number', 'duplicado_en_base_de_datos');
                } else {
                    $document = ['type' => $type->value, 'number' => $number];
                }

                $seenDocuments[$documentKey] = true;
            }
        }

        if ($row['birth_date'] !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['birth_date'])) {
            $errors[] = $this->error($line, 'birth_date', 'formato_invalido');
        }

        if ($row['locale'] !== null && ! in_array($row['locale'], $this->settings->activeLocales(), true)) {
            $errors[] = $this->error($line, 'locale', 'idioma_no_activo');
        }

        $roleCodes = [];

        if ($row['roles'] !== null) {
            $roleCodes = array_values(array_filter(array_map(trim(...), explode('|', $row['roles']))));

            foreach ($roleCodes as $code) {
                if (! $rolesByCode->has($code)) {
                    $errors[] = $this->error($line, 'roles', 'rol_no_encontrado');
                }
            }
        }

        return ['errors' => $errors, 'roles' => $roleCodes, 'document' => $document];
    }

    /**
     * @return array{line: int, column: string, code: string, message: string}
     */
    private function error(int $line, string $column, string $code): array
    {
        return [
            'line' => $line,
            'column' => $column,
            'code' => $code,
            'message' => __("core.import.{$code}", ['column' => $column]),
        ];
    }
}
