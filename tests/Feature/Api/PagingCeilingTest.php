<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Support\Paging;
use Illuminate\Http\Request;

/**
 * B-213: a list's page size is kept between 1 and Paging::MAX, so one request
 * cannot ask for every row; sizes clients use today (up to 200) are unchanged.
 */
class PagingCeilingTest extends ApiTestCase
{
    public function test_the_page_size_is_bounded(): void
    {
        $size = fn (array $q, int $default = 20, string $key = 'per_page') => Paging::perPage(Request::create('/x', 'GET', $q), $default, $key);

        $this->assertSame(20, $size([]));
        $this->assertSame(50, $size(['per_page' => '50']));
        $this->assertSame(200, $size(['per_page' => '200']));
        $this->assertSame(Paging::MAX, $size(['per_page' => '100000']));
        $this->assertSame(1, $size(['per_page' => '0']));
        $this->assertSame(1, $size(['per_page' => '-5']));
        $this->assertSame(20, $size(['per_page' => 'all']));
        $this->assertSame(Paging::MAX, $size(['limit' => '9999'], 200, 'limit'));
    }

    public function test_a_list_endpoint_never_returns_more_than_the_ceiling(): void
    {
        $admin = \App\Models\Admin::factory()->create();
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('superAdmin', 'admin'));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $h = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

        $body = $this->getJson(self::BASE.'/categories?per_page=100000', $h)->assertOk()->json();

        $this->assertSame(Paging::MAX, (int) data_get($body, 'pagination.per_page', data_get($body, 'meta.per_page', data_get($body, 'per_page'))));
    }
}
