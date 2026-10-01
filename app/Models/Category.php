<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Http\Traits\HasFile;
use Spatie\Translatable\HasTranslations;

class Category extends Model
{
    use HasFactory, HasFile, HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['name', 'logo', 'active'];

    /**
     * Without the cast a saved `true` never equals the stored `1`, so every
     * edit re-wrote `active` and the audit log called it "activated"
     * (NEW2B-6105).
     */
    protected $casts = ['active' => 'boolean'];

    public function scopeActive($q)
    {
        return $q->whereActive(true);
    }

    public function courses()
    {
        return $this->hasMany(Course::class);
    }

}
