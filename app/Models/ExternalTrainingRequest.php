<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Training a learner completed outside the LMS, submitted for L&D review
 * (D-035, D-057; Figma 2201:83481 form, 2181:116177 / 2181:116391 review).
 *
 * pending -> approved | rejected   (an admin with view-external-training)
 * pending -> withdrawn             (the learner)
 * approved | rejected -> pending   (a super admin only, audited)
 *
 * The transitions are enforced in App\Services\ExternalTrainingService under a
 * row lock; nothing here changes status on its own.
 */
class ExternalTrainingRequest extends Model
{
    public const PENDING   = 'pending';
    public const APPROVED  = 'approved';
    public const REJECTED  = 'rejected';
    public const WITHDRAWN = 'withdrawn';

    /** Statuses an admin sees and filters by. Withdrawn requests are gone for everyone. */
    public const VISIBLE = [self::PENDING, self::APPROVED, self::REJECTED];

    protected $fillable = [
        'title', 'provider', 'start_date', 'end_date', 'hours', 'cost', 'currency',
        'certificate_path', 'certificate_name', 'certificate_mime', 'certificate_size',
    ];

    protected $casts = [
        'start_date'       => 'date',
        'end_date'         => 'date',
        'hours'            => 'decimal:1',
        'cost'             => 'decimal:2',
        'certificate_size' => 'integer',
        'grant_created'    => 'boolean',
        'decided_at'       => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(QualificationSkill::class, 'qualification_skill_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function scopeVisible(Builder $q): Builder
    {
        return $q->whereIn('external_training_requests.status', self::VISIBLE);
    }
}
