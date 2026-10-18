<?php

namespace App\Reports\Support;

use App\Models\Evaluation;
use App\Reports\Column;
use App\Reports\ReportContext;
use App\Support\LocalizedJson;
use Illuminate\Support\Facades\DB;

/**
 * The questions of the evaluation template a report is filtered to.
 *
 * Evaluations are built from templates (D-054), not one fixed form, so the
 * spec's T1-T6 / P1-P6 columns become one column per question of the chosen
 * template, in its order, titled with the question itself (D-112). Rating
 * questions (five, scale: 1-5; legacy ten: 1-10) are averaged; a `ten`
 * question is the recommendation score.
 */
final class EvaluationTemplate
{
    /** @return list<object{id:int, type:string, title:?string}> */
    public static function questions(?int $templateId, ReportContext $context): array
    {
        if ($templateId === null) {
            return [];
        }

        return DB::table('evaluations')
            ->where('evaluation_category_id', $templateId)
            ->orderBy('id')
            ->get(['id', 'type', 'title'])
            ->map(fn ($q) => (object) [
                'id' => (int) $q->id,
                'type' => (string) $q->type,
                'title' => LocalizedJson::pick($q->title, $context->locale),
            ])
            ->all();
    }

    public static function isRating(object $question): bool
    {
        return in_array($question->type, ['five', 'scale'], true);
    }

    public static function isRecommendation(object $question): bool
    {
        return $question->type === 'ten';
    }

    public static function scaleMax(object $question): ?int
    {
        return Evaluation::SCALE_MAX[$question->type] ?? null;
    }

    public static function questionKey(object $question): string
    {
        return 'q_'.$question->id;
    }

    /** @return list<Column> */
    public static function answerColumns(?int $templateId, ReportContext $context, bool $averages): array
    {
        $columns = [];
        foreach (self::questions($templateId, $context) as $question) {
            if ($averages && ! self::isRating($question)) {
                continue;
            }
            $label = (string) ($question->title ?? '#'.$question->id);
            $columns[] = Column::named(
                self::questionKey($question),
                self::isRating($question) ? 'ratings' : (self::isRecommendation($question) ? 'recommendation' : 'comments'),
                self::isRating($question) || self::isRecommendation($question) ? Column::NUMBER : Column::TEXT,
                $averages ? __('reports.columns.average_of', ['question' => $label]) : $label,
            );
        }

        return $columns;
    }
}
