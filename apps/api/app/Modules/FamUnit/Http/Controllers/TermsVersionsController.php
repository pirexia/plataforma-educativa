<?php

namespace App\Modules\FamUnit\Http\Controllers;

use App\Modules\FamUnit\Application\TermsVersionService;
use App\Modules\FamUnit\Http\Requests\IndexTermsVersionsRequest;
use App\Modules\FamUnit\Http\Requests\PublishTermsVersionRequest;
use App\Modules\FamUnit\Http\Requests\StoreTermsVersionRequest;
use App\Modules\FamUnit\Http\Requests\UpdateTermsVersionRequest;
use App\Modules\FamUnit\Http\Resources\TermsVersionResource;
use App\Support\Api\PagePaginatedResponse;
use App\Support\I18n\TranslationsValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * REQ-FAM-UNIT/api.md §2.8 (1.14-b, `ADR-066 §5.5`). Versiones de los textos legales del centro; en `1.14-b` solo los dos
 * tipos de imagen. Permisos del recurso `texto_legal` (solo ámbito `todos`).
 */
class TermsVersionsController extends FamUnitController
{
    public function index(IndexTermsVersionsRequest $request, TermsVersionService $service): JsonResponse
    {
        $direction = $request->sortParam('-version_number') === 'version_number' ? 'asc' : 'desc';
        $page = $service->listing($request->filters())
            ->orderBy('version_number', $direction)
            ->orderBy('id', $direction)
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return PagePaginatedResponse::make($page, TermsVersionResource::class);
    }

    public function store(StoreTermsVersionRequest $request, TermsVersionService $service, TranslationsValidator $translations): JsonResponse
    {
        $input = $request->payload($translations);
        $version = $service->create($input['kind'], $input['title'], $input['body']);

        return (new TermsVersionResource($version))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/terms-versions/{$version->public_id}"));
    }

    public function show(Request $request, string $publicId, TermsVersionService $service): TermsVersionResource
    {
        return new TermsVersionResource($service->find($publicId));
    }

    public function update(UpdateTermsVersionRequest $request, string $publicId, TermsVersionService $service, TranslationsValidator $translations): TermsVersionResource
    {
        $version = $service->find($publicId);
        $input = $request->payload($translations, (array) $version->title_translations, (array) $version->body_translations);

        return new TermsVersionResource($service->update($publicId, $input['title'], $input['body']));
    }

    public function destroy(Request $request, string $publicId, TermsVersionService $service): Response
    {
        $service->delete($publicId);

        return response()->noContent();
    }

    public function publish(PublishTermsVersionRequest $request, string $publicId, TermsVersionService $service): TermsVersionResource
    {
        $service->find($publicId);

        return new TermsVersionResource($service->publish($publicId, $request->requiresReacceptance()));
    }
}
