<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportExportLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'report_type',
        'format',
        'filters',
        'row_count',
        'locale',
        'exported_by_admin_id',
        'exported_at',
    ];

    protected $casts = [
        'exported_at' => 'datetime',
        'filters' => 'array',
        'row_count' => 'integer',
    ];
}
