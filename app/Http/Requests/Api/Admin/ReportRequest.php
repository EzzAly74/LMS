<?php

namespace App\Http\Requests\Api\Admin;

use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportContext;
use App\Reports\ReportExporter;
use App\Reports\ReportRegistry;
use App\Services\Admin\CourseScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Query validation for GET admin/reports/{report} and its export (D-112).
 *
 * The rules come from the report's own filter definitions, so every report
 * accepts exactly its filters: each list is capped (Filter::MAX_VALUES),
 * per_page is bounded (B-21), dates are Y-m-d, and the export format is
 * allow-listed. An unknown report is a 404 in the controller. Access is
 * the route's (view-reports) plus Report::allows() in the controller.
 */
class ReportRequest extends FormRequest
{
    private ?Report $resolved = null;

    public function authorize(): bool
    {
        return true;
    }

    public function report(): ?Report
    {
        return $this->resolved ??= app(ReportRegistry::class)->find((string) $this->route('report'));
    }

    public function rules(): array
    {
        $rules = [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'format' => ['sometimes', 'string', 'in:'.implode(',', ReportExporter::FORMATS)],
        ];
        foreach ($this->report()?->filters() ?? [] as $filter) {
            $rules = array_merge($rules, $filter->rules());
            if ($filter->source === 'templates') {
                $rules[$filter->key][] = 'exists:evaluation_categories,id';
            }
        }

        return $rules;
    }

    /** `_to` never before `_from` (see Filter::rules()). */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ($this->report()?->filters() ?? [] as $filter) {
                if ($filter->type !== Filter::DATE_RANGE) {
                    continue;
                }
                $from = $this->input("{$filter->key}_from");
                $to = $this->input("{$filter->key}_to");
                if (is_string($from) && is_string($to) && $to < $from && ! $validator->errors()->has("{$filter->key}_to")) {
                    $validator->errors()->add("{$filter->key}_to", __('validation.after_or_equal', [
                        'attribute' => __('reports.filters.date_to'),
                        'date' => __('reports.filters.date_from'),
                    ]));
                }
            }
        }];
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 25);
    }

    public function pageNumber(): int
    {
        return max(1, (int) $this->integer('page', 1));
    }

    public function fileFormat(): string
    {
        return (string) $this->input('format', 'xlsx');
    }

    /** The validated filters and the reader, for the report. */
    public function context(): ReportContext
    {
        $validated = $this->validated();
        $filters = [];
        foreach ($this->report()?->filters() ?? [] as $filter) {
            $keys = $filter->type === Filter::DATE_RANGE ? ["{$filter->key}_from", "{$filter->key}_to"] : [$filter->key];
            foreach ($keys as $key) {
                if (array_key_exists($key, $validated) && $validated[$key] !== null && $validated[$key] !== []) {
                    $filters[$key] = $validated[$key];
                }
            }
        }
        $search = trim((string) ($validated['search'] ?? ''));

        return new ReportContext(
            $filters,
            app()->getLocale(),
            app(CourseScope::class)->courseIds($this->user()),
            $search !== '' ? $search : null,
        );
    }
}
