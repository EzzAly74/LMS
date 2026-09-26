<?php

namespace App\Services;

use App\Http\Traits\HasFile;
use App\Models\Admin;
use App\Models\Course;
use App\Models\ExternalTrainingRequest;
use App\Models\QualificationSkill;
use App\Models\User;
use App\Notifications\ExternalTrainingDecidedNotification;
use App\Notifications\ExternalTrainingSubmittedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * External Training (D-035; rules decided by the human 2026-09-26, D-057).
 *
 * Learners submit training they completed outside the LMS with a certificate;
 * L&D admins holding `view-external-training` approve or reject it. Approval
 * credits the hours, adds a completed-learning entry, and grants a
 * qualification only when the admin picks one. A rejection needs a reason the
 * learner sees. Pending requests can be edited or withdrawn by their learner;
 * decided ones are final, except that a super admin may reopen one (audited by
 * AuditLogServiceProvider, like every model change).
 *
 * Every transition re-reads the row under a lock, so two admins deciding at
 * once, or a learner editing while an admin approves, cannot both win.
 */
class ExternalTrainingService
{
    use HasFile;

    private const DIRECTORY = 'external-training';

    // ── Learner ──────────────────────────────────────────────────────────

    /** The learner's own requests, newest first. Withdrawn ones are gone. */
    public function mine(User $user): Collection
    {
        return ExternalTrainingRequest::query()
            ->where('user_id', $user->id)
            ->visible()
            ->with(['qualification:id,name', 'course:id,title'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    public function create(User $user, array $data, UploadedFile $certificate): ExternalTrainingRequest
    {
        $request = new ExternalTrainingRequest($this->fields($data));
        $request->user()->associate($user);
        $request->status = ExternalTrainingRequest::PENDING;
        $request->fill($this->storeCertificate($certificate));

        try {
            DB::transaction(fn () => $request->save());
        } catch (\Throwable $e) {
            Storage::disk('private')->delete($request->certificate_path);
            throw $e;
        }

        $this->notifyReviewers($request);

        return $request;
    }

    public function update(ExternalTrainingRequest $request, array $data, ?UploadedFile $certificate): ExternalTrainingRequest
    {
        $stored = $certificate ? $this->storeCertificate($certificate) : null;
        $old    = null;

        try {
            DB::transaction(function () use ($request, $data, $stored, &$old) {
                $locked = $this->lockPending($request);
                $locked->fill($this->fields($data));
                if ($stored !== null) {
                    $old = $locked->certificate_path;
                    $locked->fill($stored);
                }
                $locked->save();
                $request->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (\Throwable $e) {
            // The new file was never attached to a row: do not leave it behind.
            if ($stored !== null) {
                Storage::disk('private')->delete($stored['certificate_path']);
            }
            throw $e;
        }

        if ($old !== null) {
            Storage::disk('private')->delete($old);
        }

        return $request;
    }

    /**
     * Withdraw a pending request. The row stays for the audit trail; the
     * certificate is deleted, since nobody will review it now.
     */
    public function withdraw(ExternalTrainingRequest $request): void
    {
        $path = DB::transaction(function () use ($request) {
            $locked = $this->lockPending($request);
            $locked->status = ExternalTrainingRequest::WITHDRAWN;
            $locked->save();

            return $locked->certificate_path;
        });

        Storage::disk('private')->delete($path);
    }

    // ── Admin ────────────────────────────────────────────────────────────

    /** @param array{statuses?:list<string>, search?:string} $filters */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        $q = ExternalTrainingRequest::query()
            ->visible()
            ->with(['user:id,name,name_ar,name_en,machine_code', 'qualification:id,name', 'course:id,title']);

        if (! empty($filters['statuses'])) {
            $q->whereIn('external_training_requests.status', $filters['statuses']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($filters['search'])).'%';
            $q->where(fn ($w) => $w
                ->whereRaw('LOWER(external_training_requests.title) LIKE ?', [$term])
                ->orWhereRaw('LOWER(external_training_requests.provider) LIKE ?', [$term])
                ->orWhereHas('user', fn ($u) => $u
                    ->whereRaw('LOWER(users.name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(users.machine_code) LIKE ?', [$term])));
        }

        return $q->orderByRaw("CASE WHEN external_training_requests.status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('external_training_requests.created_at')
            ->orderByDesc('external_training_requests.id')
            ->paginate($perPage);
    }

    /**
     * The three tiles on Figma 2181:116177: pending now, requests submitted
     * this calendar year, and rejected this year. Withdrawn never count.
     *
     * @return array{pending:int, this_year:int, rejected_this_year:int, year:int}
     */
    public function stats(): array
    {
        $year = (int) now()->year;
        $row  = DB::table('external_training_requests')
            ->whereIn('status', ExternalTrainingRequest::VISIBLE)
            ->selectRaw("SUM(status = 'pending') AS pending")
            ->selectRaw('SUM(YEAR(created_at) = ?) AS this_year', [$year])
            ->selectRaw("SUM(status = 'rejected' AND YEAR(created_at) = ?) AS rejected_this_year", [$year])
            ->first();

        return [
            'pending'            => (int) ($row->pending ?? 0),
            'this_year'          => (int) ($row->this_year ?? 0),
            'rejected_this_year' => (int) ($row->rejected_this_year ?? 0),
            'year'               => $year,
        ];
    }

    public function approve(ExternalTrainingRequest $request, ?Admin $by, ?int $qualificationId, ?int $courseId): ExternalTrainingRequest
    {
        $decided = DB::transaction(function () use ($request, $by, $qualificationId, $courseId) {
            $locked = $this->lockPending($request);

            $grantCreated = false;
            if ($qualificationId !== null) {
                $held = DB::table('user_qualification_skill')
                    ->where('user_id', $locked->user_id)
                    ->where('qualification_skill_id', $qualificationId)
                    ->exists();
                if (! $held) {
                    DB::table('user_qualification_skill')->insert([
                        'user_id'                => $locked->user_id,
                        'qualification_skill_id' => $qualificationId,
                        'assigned_by'            => $by?->id,
                        'assigned_at'            => now(),
                        'note'                   => mb_substr(__('messages.external_training_grant_note', ['id' => $locked->id]), 0, 500),
                        'created_at'             => now(),
                        'updated_at'             => now(),
                    ]);
                    $grantCreated = true;
                }
            }

            $locked->status                 = ExternalTrainingRequest::APPROVED;
            $locked->qualification_skill_id = $qualificationId;
            $locked->course_id              = $courseId;
            $locked->grant_created          = $grantCreated;
            $locked->rejection_reason       = null;
            $locked->decided_by             = $by?->id;
            $locked->decided_at             = now();
            $locked->save();

            return $locked;
        });

        $decided->user->notify(new ExternalTrainingDecidedNotification($decided));

        return $decided;
    }

    public function reject(ExternalTrainingRequest $request, ?Admin $by, string $reason): ExternalTrainingRequest
    {
        $decided = DB::transaction(function () use ($request, $by, $reason) {
            $locked = $this->lockPending($request);
            $locked->status           = ExternalTrainingRequest::REJECTED;
            $locked->rejection_reason = $reason;
            $locked->decided_by       = $by?->id;
            $locked->decided_at       = now();
            $locked->save();

            return $locked;
        });

        $decided->user->notify(new ExternalTrainingDecidedNotification($decided));

        return $decided;
    }

    /**
     * A super admin puts a decided request back to pending. A qualification
     * grant is removed only if this request's approval created it.
     */
    public function reopen(ExternalTrainingRequest $request): ExternalTrainingRequest
    {
        return DB::transaction(function () use ($request) {
            $locked = ExternalTrainingRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (! in_array($locked->status, [ExternalTrainingRequest::APPROVED, ExternalTrainingRequest::REJECTED], true)) {
                throw ValidationException::withMessages(['request' => __('messages.external_training_not_decided')]);
            }

            if ($locked->grant_created && $locked->qualification_skill_id !== null) {
                DB::table('user_qualification_skill')
                    ->where('user_id', $locked->user_id)
                    ->where('qualification_skill_id', $locked->qualification_skill_id)
                    ->delete();
            }

            $locked->status                 = ExternalTrainingRequest::PENDING;
            $locked->qualification_skill_id = null;
            $locked->course_id              = null;
            $locked->grant_created          = false;
            $locked->rejection_reason       = null;
            $locked->decided_by             = null;
            $locked->decided_at             = null;
            $locked->save();

            return $locked;
        });
    }

    /**
     * The review screen's pickers: every qualification, and the courses a
     * request can be recorded against (Q-042), names in the request locale.
     * Bounded - these are small reference tables.
     *
     * @return array{qualifications:list<array{id:int,name:string}>, courses:list<array{id:int,title:string}>}
     */
    public function options(): array
    {
        $locale = app()->getLocale();

        return [
            'qualifications' => QualificationSkill::query()->orderBy('id')->limit(1000)->get(['id', 'name'])
                ->map(fn (QualificationSkill $q) => ['id' => $q->id, 'name' => $q->getTranslation('name', $locale)])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'courses' => Course::query()->orderBy('id')->limit(1000)->get(['id', 'title'])
                ->map(fn (Course $c) => ['id' => $c->id, 'title' => (string) $c->getTranslation('title', $locale)])
                ->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
        ];
    }

    /** Approved external hours in a calendar year (by end date) - credited to the learner's training hours. */
    public function approvedHours(int $userId, int $year): float
    {
        return (float) ExternalTrainingRequest::query()
            ->where('user_id', $userId)
            ->where('status', ExternalTrainingRequest::APPROVED)
            ->whereYear('end_date', $year)
            ->sum('hours');
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function lockPending(ExternalTrainingRequest $request): ExternalTrainingRequest
    {
        $locked = ExternalTrainingRequest::query()->lockForUpdate()->findOrFail($request->id);
        if (! $locked->isPending()) {
            throw ValidationException::withMessages(['request' => __('messages.external_training_not_pending')]);
        }

        return $locked;
    }

    private function fields(array $data): array
    {
        return [
            'title'      => trim($data['title']),
            'provider'   => trim($data['provider']),
            'start_date' => $data['start_date'],
            'end_date'   => $data['end_date'],
            'hours'      => $data['hours'],
            'cost'       => $data['cost'] ?? null,
            'currency'   => 'EGP',
        ];
    }

    /** Store on the private disk under a server-made name; keep a display name only. */
    private function storeCertificate(UploadedFile $file): array
    {
        $original = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', basename((string) $file->getClientOriginalName())) ?: 'certificate';

        return [
            'certificate_path' => $this->uploadPrivateFile(self::DIRECTORY, $file),
            'certificate_name' => mb_substr($original, 0, 191),
            'certificate_mime' => (string) $file->getMimeType(),
            'certificate_size' => (int) $file->getSize(),
        ];
    }

    /** Admins who review external training (and super admins, who see everything). */
    private function notifyReviewers(ExternalTrainingRequest $request): void
    {
        $notification = new ExternalTrainingSubmittedNotification($request);

        Admin::query()
            ->where(fn ($q) => $q->permission('view-external-training')->orWhereHas('roles', fn ($r) => $r->where('name', 'superAdmin')))
            ->get()
            ->each(fn (Admin $admin) => $admin->notify($notification));
    }
}
