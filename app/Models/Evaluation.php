<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * A question of an evaluation template.
 *
 * `type`: five (star rating 1-5) and scale (1-5 with worded ends) are what the
 * builder creates (Q-051); ten (1-10) and text are legacy and stay readable.
 */
class Evaluation extends Model
{
    use HasFactory, HasTranslations;

    /** Types the builder may create. */
    public const BUILDER_TYPES = ['five', 'scale'];

    /** Scale maximum per type; text has none. */
    public const SCALE_MAX = ['five' => 5, 'scale' => 5, 'ten' => 10];

    public array $translatable = ['title', 'scale_label_min', 'scale_label_max'];

    protected $fillable = ['evaluation_category_id', 'type', 'title', 'is_required', 'scale_label_min', 'scale_label_max'];

    protected $casts = ['is_required' => 'boolean'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(EvaluationCategory::class, 'evaluation_category_id');
    }
}
