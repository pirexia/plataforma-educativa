<?php

namespace App\Http\Middleware;

use App\Modules\Core\Domain\TenantSettingsReader;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-038 §11: orden de resolución del idioma, único middleware para toda
 * la API. Un idioma pedido y no disponible no es un error: se degrada al
 * siguiente de la lista. El resuelto se devuelve siempre en
 * Content-Language, incluso en respuestas de error.
 *
 * Va después de resolve-tenant (necesita active_locales del tenant) y,
 * cuando exista sesión (1.2), antes de que el controlador use __(). En
 * 1.1 el paso 1 (person.locale del autenticado) solo actúa en los tests
 * que usan actingAs(), porque no hay login real todavía.
 */
class ResolveApiLocale
{
    /** @var array<string, string> Código de negocio (ADR-021) -> carpeta lang/ de Laravel. */
    private const TO_LARAVEL_LOCALE = [
        'es-ES' => 'es',
        'en' => 'en',
        'de' => 'de',
        'fr' => 'fr',
    ];

    public function __construct(
        private readonly TenantSettingsReader $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $activeLocales = $this->settings->activeLocales();
        $resolved = $this->fromAuthenticatedUser($request, $activeLocales)
            ?? $this->fromAcceptLanguage($request, $activeLocales)
            ?? $this->settings->defaultLocale();

        App::setLocale(self::TO_LARAVEL_LOCALE[$resolved] ?? 'es');

        $response = $next($request);
        $response->headers->set('Content-Language', $resolved);

        return $response;
    }

    /**
     * @param  list<string>  $activeLocales
     */
    private function fromAuthenticatedUser(Request $request, array $activeLocales): ?string
    {
        $user = $request->user();
        $locale = $user?->person?->locale;

        return is_string($locale) && in_array($locale, $activeLocales, true) ? $locale : null;
    }

    /**
     * @param  list<string>  $activeLocales
     */
    private function fromAcceptLanguage(Request $request, array $activeLocales): ?string
    {
        foreach ($this->parseAcceptLanguage($request->header('Accept-Language', '')) as $tag) {
            $primary = strtolower(explode('-', $tag)[0]);

            foreach ($activeLocales as $active) {
                if (strtolower(explode('-', $active)[0]) === $primary) {
                    return $active;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string> etiquetas de idioma en orden de preferencia (q descendente)
     */
    private function parseAcceptLanguage(string $header): array
    {
        if ($header === '') {
            return [];
        }

        $weighted = [];

        foreach (explode(',', $header) as $part) {
            $segments = explode(';q=', trim($part));
            $tag = trim($segments[0]);
            $quality = isset($segments[1]) ? (float) $segments[1] : 1.0;

            if ($tag !== '') {
                $weighted[] = [$tag, $quality];
            }
        }

        usort($weighted, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        return array_column($weighted, 0);
    }
}
