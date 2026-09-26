<?php

namespace App\Http\Requests\Api\Admin;

/**
 * Validation for POST admin/evaluations/templates/import: the same spreadsheet
 * upload rules as the qualifications import (content-sniffed type, 5 MB cap),
 * so both imports accept and refuse exactly the same files.
 */
class AdminEvaluationImportRequest extends QualificationSkillImportRequest
{
}
