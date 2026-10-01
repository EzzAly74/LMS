<?php

namespace App\Http\Controllers\apis\Admin;

use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\AdminUserIndexRequest;
use App\Http\Resources\Admin\AdminUserListResource;
use App\Models\Instructor;
use App\Services\Admin\AdminUserService;
use App\Services\Admin\CourseScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Learning > Learners (D-075): website learners, the HR employees who sign in
 * on the website. Split from Users, which now manages Dashboard accounts, and
 * gated on its own `view-learners` permission.
 *
 * An account limited to its own courses (D-074) lists only learners enrolled
 * in those courses.
 */
class AdminLearnerController extends ApiController
{
    public function __construct(
        private readonly AdminUserService $people,
        private readonly CourseScope $scope,
    ) {}

    /** GET /api/v1/admin/learners */
    public function index(AdminUserIndexRequest $request): JsonResponse
    {
        $principal = $request->user();
        $status = $request->input('status');

        $page = $this->people->paginate(
            role:           'learner',
            status:         in_array($status, ['active', 'inactive', 'deactivated'], true) ? $status : null,
            search:         $request->string('search')->toString() ?: null,
            perPage:        $request->perPage(),
            learnerFilters: $request->learnerFilters(),
            constrain:      fn (Builder $q) => $this->scope->constrainLearners($q, $principal, 'p.id'),
        );

        return $this->paginated(__('messages.retrieved'), AdminUserListResource::collection($page));
    }

    /**
     * GET /api/v1/admin/learners/filter-options: the instructors for the
     * "course instructor" filter. A scoped account sees only itself.
     */
    public function filterOptions(Request $request): JsonResponse
    {
        $locale = app()->getLocale();
        $principal = $request->user();
        $query = Instructor::query()->orderBy('id');

        if ($this->scope->isScoped($principal)) {
            $query->whereKey($principal->instructor_id ?? 0);
        }

        $instructors = $query->get(['id', 'name', 'email'])->map(fn (Instructor $i) => [
            'id'    => (int) $i->id,
            'name'  => $i->getTranslation('name', $locale, false) ?: $i->getTranslation('name', 'en', false),
            'email' => $i->email,
        ])->values()->all();

        return $this->success(__('messages.retrieved'), ['instructors' => $instructors]);
    }
}
