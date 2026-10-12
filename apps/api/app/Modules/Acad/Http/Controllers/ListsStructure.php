<?php

namespace App\Modules\Acad\Http\Controllers;

use App\Modules\Acad\Application\StructureYears;
use App\Modules\Acad\Http\Requests\IndexStructureRequest;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Support\Api\PagePaginatedResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Listados de nivel superior (`api.md §3`): curso académico (`?academic_year=`
 * o el activo, `RN-ACAD-08`), orden por lista blanca con desempate por `id`
 * (`RN-ACAD-04`: a igual `position`, por `code`) y paginación por página.
 */
trait ListsStructure
{
    private function listingYear(IndexStructureRequest $request, StructureYears $years): AcademicYearSummary
    {
        $param = $request->query('academic_year');

        return $years->forListing(is_string($param) ? $param : null, $this->actor($request));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  class-string<JsonResource>  $resource
     */
    private function paginated(Builder $query, IndexStructureRequest $request, int $academicYearId, string $resource): JsonResponse
    {
        $sort = $request->string('sort', 'position')->value();
        $column = ltrim($sort, '-');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        $query->where('academic_year_id', $academicYearId)->orderBy($column, $direction);

        if ($column === 'position') {
            $query->orderBy('code');
        }

        $query->orderBy('id');

        return PagePaginatedResponse::make($query->paginate($request->integer('per_page', 25))->withQueryString(), $resource);
    }
}
