<?php

namespace App\Http\Requests\Api\Admin;

use App\Reports\ReportExporter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET admin/reports/export-all (Figma 2201:81876, "Export All"): the file
 * format of every report in the zip. Access is the route's (view-reports).
 */
class ReportExportAllRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'format' => ['sometimes', 'string', 'in:'.implode(',', ReportExporter::FORMATS)],
        ];
    }

    public function fileFormat(): string
    {
        return (string) $this->validated('format', 'xlsx');
    }
}
