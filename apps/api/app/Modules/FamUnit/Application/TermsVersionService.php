<?php

namespace App\Modules\FamUnit\Application;

use App\Http\Middleware\ResolveApiLocale;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Modules\FamUnit\Domain\Models\TermsVersion;
use App\Support\I18n\Locales;
use App\Support\I18n\TranslationResolver;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/funcional.md §3.9, §4.9, `RN-FAM-UNIT-60`, `-73` y `api.md §2.8` (1.14-b, `ADR-066 §5.5`): versiones de
 * los textos legales del centro. `1.14-b` gestiona solo los dos tipos de imagen; `condiciones_uso` se rechaza hasta
 * `1.14-c` (`422 terms.kind_not_available`).
 *
 * Una versión pasa de `borrador` a `publicada` y de `publicada` a `retirada`; como mucho una `publicada` por centro y tipo
 * (índice único parcial); la numeración es correlativa por centro y tipo y no se reutiliza. Una versión `publicada` o
 * `retirada` no se edita ni se borra (`409 not_draft`). Publicar una versión de imagen **no altera ningún estado** de
 * consentimiento (`RN-FAM-UNIT-70`): las decisiones apuntan a la versión con la que se tomaron.
 *
 * El recurso de permisos `texto_legal` solo admite el ámbito `todos`, así que sus lecturas no pasan por `ScopedQuery`
 * (`permisos.md §3.1`).
 */
final class TermsVersionService
{
    /** Longitud máxima, en caracteres, del título y del cuerpo de cada idioma (`ADR-062 §5.2`). */
    public const TITLE_MAX_LENGTH = 200;

    public const BODY_MAX_LENGTH = 20000;

    /** Tipos que `1.14-b` gestiona; `condiciones_uso` entra con `1.14-c` (`RN-FAM-UNIT-73`). */
    public const AVAILABLE_KINDS = [TermsVersion::KIND_IMAGE_WEB, TermsVersion::KIND_IMAGE_SOCIAL];

    public function __construct(
        private readonly TenantSettingsReader $settings,
        private readonly TenantContext $tenant,
    ) {}

    /** Detalle: `404` si no existe o es de otro centro. */
    public function find(string $publicId): TermsVersion
    {
        return TermsVersion::query()->where('public_id', $publicId)->firstOrFail();
    }

    public function findOrNull(string $publicId): ?TermsVersion
    {
        return TermsVersion::query()->where('public_id', $publicId)->first();
    }

    /**
     * @param  array{kind: list<string>, status: list<string>}  $filters
     * @return Builder<TermsVersion>
     */
    public function listing(array $filters): Builder
    {
        $query = TermsVersion::query();

        if ($filters['kind'] !== []) {
            $query->whereIn('kind', $filters['kind']);
        }

        if ($filters['status'] !== []) {
            $query->whereIn('status', $filters['status']);
        }

        return $query;
    }

    /** La versión `publicada` del tipo, o `null` si el centro no ha publicado ninguna. */
    public function published(string $kind): ?TermsVersion
    {
        return TermsVersion::query()->where('kind', $kind)->where('status', TermsVersion::STATUS_PUBLISHED)->first();
    }

    /**
     * `RN-FAM-UNIT-73`, `-69`: alta de un `borrador`. El número de versión es correlativo por centro y tipo (el
     * máximo existente, también de borradores borrados, más uno), bajo un bloqueo consultivo del centro y el tipo.
     *
     * @param  array<string, string>  $title
     * @param  array<string, string>  $body
     */
    public function create(string $kind, array $title, array $body): TermsVersion
    {
        if (! in_array($kind, TermsVersion::KINDS, true)) {
            throw FamUnitErrors::invalid('kind', 'fam_unit.terms.kind_invalid');
        }

        if (! in_array($kind, self::AVAILABLE_KINDS, true)) {
            throw FamUnitErrors::invalid('kind', 'fam_unit.terms.kind_not_available');
        }

        return DB::transaction(function () use ($kind, $title, $body): TermsVersion {
            $this->lockKind($kind);

            $max = TermsVersion::withTrashed()->where('kind', $kind)->max('version_number');

            $version = new TermsVersion(['title_translations' => $title, 'body_translations' => $body]);
            $version->kind = $kind;
            $version->version_number = ((int) $max) + 1;
            $version->status = TermsVersion::STATUS_DRAFT;
            $version->requires_reacceptance = false;
            $version->save();

            return $version;
        });
    }

    /**
     * `RN-FAM-UNIT-73`: solo un `borrador` se edita (`409 not_draft`). `$title` y `$body` ya están fusionados con lo
     * almacenado por el validador de traducciones (`null` = no se tocan).
     *
     * @param  array<string, string>|null  $title
     * @param  array<string, string>|null  $body
     */
    public function update(string $publicId, ?array $title, ?array $body): TermsVersion
    {
        return DB::transaction(function () use ($publicId, $title, $body): TermsVersion {
            $version = TermsVersion::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $this->assertDraft($version);

            if ($title !== null) {
                $version->title_translations = $title;
            }

            if ($body !== null) {
                $version->body_translations = $body;
            }

            $version->save();

            return $version;
        });
    }

    /** `RN-FAM-UNIT-73`: solo se borra (lógicamente) un `borrador`; `409 not_draft` en cualquier otro estado. */
    public function delete(string $publicId): void
    {
        DB::transaction(function () use ($publicId): void {
            $version = TermsVersion::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $this->assertDraft($version);
            $version->delete();
        });
    }

    /**
     * `RN-FAM-UNIT-60`, `-70`, `-73`: publica un `borrador`. La versión publicada anterior **del mismo tipo** pasa a
     * `retirada` en la misma transacción; las de otros tipos no cambian. Exige el idioma por defecto del centro en
     * título y cuerpo (`422 default_locale_missing`, `RN-FAM-UNIT-65`) y no admite `requires_reacceptance` en un tipo
     * de imagen (`422 requires_reacceptance_not_allowed`). No toca ninguna decisión.
     */
    public function publish(string $publicId, bool $requiresReacceptance): TermsVersion
    {
        return $this->inTransaction(function () use ($publicId, $requiresReacceptance): TermsVersion {
            $version = TermsVersion::query()->where('public_id', $publicId)->firstOrFail();
            $this->lockKind((string) $version->kind);

            // Releída ya bajo el bloqueo: dos publicaciones simultáneas del mismo tipo se serializan.
            $version = TermsVersion::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $this->assertDraft($version);

            if ($requiresReacceptance && $version->kind !== TermsVersion::KIND_TERMS_OF_USE) {
                throw FamUnitErrors::invalid('requires_reacceptance', 'fam_unit.terms.requires_reacceptance_not_allowed');
            }

            $default = $this->settings->defaultLocale();

            foreach (['title_translations', 'body_translations'] as $column) {
                $text = $version->{$column}[$default] ?? null;

                if (! is_string($text) || trim($text) === '') {
                    throw FamUnitErrors::invalid($column, 'fam_unit.terms.default_locale_missing', ['locale' => $default]);
                }
            }

            $current = TermsVersion::query()
                ->where('kind', $version->kind)
                ->where('status', TermsVersion::STATUS_PUBLISHED)
                ->lockForUpdate()
                ->first();

            if ($current !== null) {
                $current->status = TermsVersion::STATUS_RETIRED;
                $current->save();
            }

            $version->status = TermsVersion::STATUS_PUBLISHED;
            $version->requires_reacceptance = $requiresReacceptance;
            $version->published_at = now();
            $version->save();

            return $version;
        });
    }

    /**
     * Título y cuerpo en el idioma de la petición con la caída de `ADR-062 §5.3` (idioma pedido → idioma por defecto
     * del centro → `es-ES` → primer idioma presente), y el idioma en que **vienen de verdad**: el primero de la caída
     * para el que existen **a la vez** título y cuerpo, de modo que una sola etiqueta describe el texto entero. Es el
     * que se registra como `shown_locale` en una decisión por el canal `portal` (`RN-FAM-UNIT-43`, `OPEN-FAM-UNIT-25`).
     *
     * @return array{title: string, body: string, locale: string}
     */
    public function resolve(TermsVersion $version): array
    {
        $title = (array) $version->title_translations;
        $body = (array) $version->body_translations;
        $requested = $this->requestedLocale();
        $default = $this->settings->defaultLocale();

        // Solo los idiomas con título y cuerpo no vacíos.
        $both = array_filter($body, static fn ($text, $locale): bool => is_string($text) && trim($text) !== ''
            && is_string($title[$locale] ?? null) && trim((string) $title[$locale]) !== '', ARRAY_FILTER_USE_BOTH);

        $resolved = TranslationResolver::resolve($both, $requested, $default);

        if ($resolved !== null) {
            return ['title' => (string) $title[$resolved->locale], 'body' => $resolved->text, 'locale' => $resolved->locale];
        }

        // Sin ningún idioma común (el validador lo impide al escribir): cada campo por su cuenta.
        $titleText = TranslationResolver::resolve($title, $requested, $default);
        $bodyText = TranslationResolver::resolve($body, $requested, $default);

        return [
            'title' => $titleText->text ?? '',
            'body' => $bodyText->text ?? '',
            'locale' => $bodyText->locale ?? $default,
        ];
    }

    /** El idioma de la petición como código de negocio (`es-ES`, `en`...), o `null` si no es uno conocido. */
    public function requestedLocale(): ?string
    {
        $requested = array_search(app()->getLocale(), ResolveApiLocale::TO_LARAVEL_LOCALE, true);

        return is_string($requested) && Locales::isKnown($requested) ? $requested : null;
    }

    private function assertDraft(TermsVersion $version): void
    {
        if ($version->status !== TermsVersion::STATUS_DRAFT) {
            throw FamUnitErrors::conflict('status', 'fam_unit.terms.not_draft');
        }
    }

    /** Bloqueo consultivo de la transacción por centro y tipo: serializa numeración y publicación. */
    private function lockKind(string $kind): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["fam_unit_terms:{$this->tenant->tenantId()}:{$kind}"]);
    }

    /**
     * La violación del índice único parcial de «una publicada por tipo» (SQLSTATE 23505) no debería ocurrir bajo el
     * bloqueo consultivo; si ocurriera (otro escritor), se responde como una versión que ya no es borrador, nunca `500`.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function inTransaction(Closure $write): mixed
    {
        try {
            return DB::transaction($write);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505' && str_contains($e->getMessage(), 'terms_versions_tenant_kind_published_unique')) {
                throw FamUnitErrors::conflict('status', 'fam_unit.terms.not_draft');
            }

            throw $e;
        }
    }
}
