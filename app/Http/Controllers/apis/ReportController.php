<?php

namespace App\Http\Controllers\apis;

use App\Models\User;
use App\Models\UsersCourse;
use App\Models\UserExam;
use App\Support\PersonName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends ApiController
{
    public function complianceByJobTitle(Request $request): JsonResponse|StreamedResponse
    {
        $data = User::select('job_title', DB::raw('COUNT(*) as total_users'))
            ->groupBy('job_title')
            ->whereNotNull('job_title')
            ->get()
            ->map(fn ($row) => [
                'job_title'   => $row->job_title,
                'total_users' => $row->total_users,
            ]);

        return $this->respondReport($request, $data->toArray(), ['job_title', 'total_users']);
    }

    public function individualCompliance(Request $request): JsonResponse
    {
        $users = User::select('id', DB::raw(PersonName::userSql('users').' as name'), 'email', 'job_title', 'department_name')
            ->withCount('courses as enrolled_courses')
            ->paginate(\App\Support\Paging::perPage($request, 50));

        return $this->paginated(__('messages.retrieved'), $users);
    }

    public function attendance(Request $request): JsonResponse
    {
        $data = DB::table('attendances')
            ->join('users', 'users.id', '=', 'attendances.user_id')
            ->join('courses', 'courses.id', '=', 'attendances.course_id')
            ->select(
                'attendances.id',
                DB::raw(PersonName::userSql('users').' as user_name'),
                'users.email as user_email',
                'attendances.course_name',
                'attendances.course_id',
                'attendances.attendance_hours',
                'attendances.course_hours',
                'attendances.user_department',
                'attendances.created_at'
            )
            ->orderByDesc('attendances.created_at')
            ->paginate(\App\Support\Paging::perPage($request, 50));

        return $this->paginated(__('messages.retrieved'), $data);
    }

    public function completion(Request $request): JsonResponse
    {
        $data = UsersCourse::with(['user' => $this->userWithLocalizedName(...), 'course:id,title'])
            ->orderByDesc('created_at')
            ->paginate(\App\Support\Paging::perPage($request, 50));

        return $this->paginated(__('messages.retrieved'), $data);
    }

    public function scores(Request $request): JsonResponse
    {
        $data = UserExam::with(['user' => $this->userWithLocalizedName(...), 'exam.course:id,title'])
            ->select('id', 'user_id', 'exam_id', 'course_id', 'user_degree', 'status', 'created_at')
            ->orderByDesc('created_at')
            ->paginate(\App\Support\Paging::perPage($request, 50));

        return $this->paginated(__('messages.retrieved'), $data);
    }

    public function certificateStatus(Request $request): JsonResponse
    {
        $data = DB::table('users_courses')
            ->join('users', 'users.id', '=', 'users_courses.user_id')
            ->join('courses', 'courses.id', '=', 'users_courses.course_id')
            ->select(
                DB::raw(PersonName::userSql('users').' as user_name'),
                'users.email',
                'courses.id as course_id',
                'users_courses.created_at as enrolled_at'
            )
            ->orderByDesc('users_courses.created_at')
            ->paginate(\App\Support\Paging::perPage($request, 50));

        return $this->paginated(__('messages.retrieved'), $data);
    }

    /** Eager-load constraint: id, email and `name` in the request's language. */
    private function userWithLocalizedName($query): void
    {
        $query->select('id', 'email', DB::raw(PersonName::userSql('users').' as name'));
    }

    private function respondReport(Request $request, array $rows, array $headers): JsonResponse|StreamedResponse
    {
        if ($request->get('format') === 'csv') {
            return $this->csvResponse('report.csv', $headers, $rows);
        }

        return $this->success(__('messages.retrieved'), $rows);
    }

    private function csvResponse(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                $line = [];
                foreach ($headers as $h) {
                    $line[] = is_array($row) ? ($row[$h] ?? '') : (is_object($row) ? ($row->{$h} ?? '') : '');
                }
                fputcsv($out, $line);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
