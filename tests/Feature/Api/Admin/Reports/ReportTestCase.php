<?php

namespace Tests\Feature\Api\Admin\Reports;

use App\Models\Admin;
use App\Models\Instructor;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Api\ApiTestCase;

/**
 * Helpers for the NAS report tests (D-112).
 */
abstract class ReportTestCase extends ApiTestCase
{
    /** @var array<string, string>|null */
    private ?array $headers = null;

    /** @return array<string, string> */
    protected function admin(): array
    {
        return $this->headers ??= $this->adminToken()['headers'];
    }

    /** An admin holding only these permissions. @return array<string, string> */
    protected function adminWith(array $permissions, ?callable $tweak = null): array
    {
        $role = Role::findOrCreate('reports-test-'.uniqid(), 'admin');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'admin'));
        }
        if ($tweak !== null) {
            $tweak($role);
        }
        $admin = Admin::factory()->create();
        $admin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken];
    }

    protected function instructor(string $en = 'Ahmed Zidan', string $ar = 'أحمد زيدان'): Instructor
    {
        return Instructor::query()->create([
            'name' => ['en' => $en, 'ar' => $ar], 'email' => 'inst'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]);
    }

    /** @param  array<string, mixed>  $query */
    protected function page(string $report, array $query = [], ?array $headers = null, string $locale = 'en'): TestResponse
    {
        return $this->getJson(self::BASE."/admin/reports/{$report}?".http_build_query($query), ($headers ?? $this->admin()) + ['Accept-Language' => $locale]);
    }

    /**
     * The report's rows, keyed by column key.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    protected function rows(string $report, array $query = [], string $locale = 'en'): array
    {
        return $this->page($report, $query + ['per_page' => 100], null, $locale)->assertOk()->json('result.rows');
    }

    /**
     * The CSV export: the header lines (label => value) and the data rows keyed by heading.
     *
     * @param  array<string, mixed>  $query
     * @return array{header: array<string, string>, headings: list<string>, rows: list<array<string, string>>}
     */
    protected function csv(string $report, array $query = [], string $locale = 'en', ?array $headers = null): array
    {
        $body = $this->get(self::BASE."/admin/reports/{$report}/export?".http_build_query($query + ['format' => 'csv']), ($headers ?? $this->admin()) + ['Accept-Language' => $locale])
            ->assertOk()->streamedContent();
        $lines = preg_split('/\r?\n/', preg_replace('/^\xEF\xBB\xBF/', '', $body));
        $header = [];
        $i = 0;
        for (; $i < count($lines) && trim($lines[$i]) !== ''; $i++) {
            $cells = str_getcsv($lines[$i]);
            $header[$cells[0]] = $cells[1] ?? '';
        }
        $headings = str_getcsv($lines[$i + 1] ?? '');
        $rows = [];
        foreach (array_slice($lines, $i + 2) as $line) {
            if ($line !== '') {
                $rows[] = array_combine($headings, str_getcsv($line));
            }
        }

        return ['header' => $header, 'headings' => $headings, 'rows' => $rows];
    }
}
