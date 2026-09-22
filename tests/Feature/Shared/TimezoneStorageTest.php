<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Models\User;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TimezoneStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_instants_use_utc_while_business_day_follows_salta(): void
    {
        CarbonImmutable::setTestNow('2026-09-23T02:30:00Z');

        try {
            $user = User::factory()->create();

            $this->assertSame('UTC', config('app.timezone'));
            $this->assertSame('UTC', DB::selectOne("select current_setting('TimeZone') as zone")->zone);
            $this->assertSame('2026-09-22', BusinessDate::today()->toDateString());
            $this->assertSame('2026-09-22', BusinessDate::fromInstant(CarbonImmutable::now())->toDateString());
            $this->assertSame('2026-09-22T03:00:00.000000Z', BusinessDate::startOfDay('2026-09-22')->toISOString());
            $this->assertSame('2026-09-23T02:30:00.000000Z', $user->created_at->toISOString());

            $row = DB::selectOne('select created_at::text as created_at from users where id = ?', [$user->id]);
            $this->assertSame('2026-09-23 02:30:00+00', $row->created_at);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_legacy_local_timestamp_is_preserved_as_the_same_instant(): void
    {
        $user = User::factory()->create();
        $migration = require database_path('migrations/2026_09_22_120000_convert_legacy_timestamps_to_timestamptz.php');

        $migration->down();
        DB::table('users')->where('id', $user->id)->update(['created_at' => '2026-09-22 12:00:00']);
        $migration->up();

        $this->assertSame(
            '2026-09-22 15:00:00+00',
            DB::selectOne('select created_at::text as created_at from users where id = ?', [$user->id])->created_at,
        );
    }

    public function test_all_timestamp_columns_have_timezone(): void
    {
        $remaining = DB::selectOne("select count(*) as total from information_schema.columns where table_schema = 'public' and data_type = 'timestamp without time zone'");
        $this->assertSame(0, (int) $remaining->total);
    }
}
