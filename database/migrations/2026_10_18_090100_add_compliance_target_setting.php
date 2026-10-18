<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The organisation-wide compliance target (D-112, human 2026-10-18: one
 * target for every job title, 90%). The Compliance by Job Title report reads
 * it for "Target %" and "Status". Insert-if-absent, so an edited value is
 * never overwritten by a re-run.
 */
return new class extends Migration
{
    private const KEY = 'compliance_target';

    public function up(): void
    {
        if (DB::table('settings')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'key'        => self::KEY,
            'value'      => '90',
            'type'       => 'number',
            'label'      => 'Compliance Target (%)',
            'module'     => 'platform',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::KEY)->delete();
    }
};
