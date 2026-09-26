<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->rows() as $row) {
            Setting::updateOrCreate(
                ['key' => $row['key'], 'module' => $row['module']],
                $row
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        return [
            [
                'id' => 26,
                'type' => 'file',
                'label' => 'الشهادة',
                'key' => 'certificate',
                'value' => 'Setting/a1JKpvYV2OmBZr1xe4hjcadf2ebfbe3bb67a4089e222013566fd.jpg',
                'module' => 'home',
                'created_at' => '2025-08-11 21:34:53',
                'updated_at' => '2025-09-20 09:57:36',
            ],
        ];
    }
}
