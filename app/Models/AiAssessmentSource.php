<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One uploaded file or pasted text of an AI assessment (D-113). */
class AiAssessmentSource extends Model
{
    public const KINDS = ['pdf', 'docx', 'pptx', 'text'];

    protected $fillable = [
        'ai_assessment_id', 'kind', 'name', 'path', 'size', 'extracted_text', 'units',
        'provider_file_uri', 'provider_file_expires_at',
    ];

    protected $casts = [
        'size'                     => 'integer',
        'units'                    => 'integer',
        'provider_file_expires_at' => 'datetime',
    ];

    protected $hidden = ['extracted_text', 'path', 'provider_file_uri'];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(AiAssessment::class, 'ai_assessment_id');
    }
}
