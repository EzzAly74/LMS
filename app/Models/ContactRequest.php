<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactRequest extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'phone', 'job_title', 'company_name', 'guests', 'locale'];

    protected $casts = [
        'guests' => 'array',
    ];
}
