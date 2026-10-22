<?php

use App\Models\Branch;
use App\Models\User;
use App\Support\Backup;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

/**
 * pg_restore --data-only chiqaradigan SQL'ning o'zi (COPY bloklari) — bazaning hozirgi xarita jadvallaridan.
 * pg_dump/pg_restore Mac'da yo'q, COPY formati esa aynan shu.
 */
function mapSqlFromDatabase(): string
{
    $pdo = DB::connection()->getPdo();
    $sql = "SET statement_timeout = 0;\nSELECT pg_catalog.set_config('search_path', '', false);\n\n";
    foreach (Backup::MAP_TABLES as $table) {
        $columns = Schema::getColumnListing($table);
        $sql .= "--\n-- Data for Name: {$table}; Type: TABLE DATA; Schema: public\n--\n\n";
        $sql .= 'COPY public.'.$table.' ('.implode(', ', $columns).") FROM stdin;\n";
        $sql .= implode('', $pdo->pgsqlCopyToArray($table, "\t", '\\\\N', implode(',', $columns)) ?: []);
        $sql .= "\\.\n\n";
    }
    $file = tempnam(sys_get_temp_dir(), 'map-sql');
    File::put($file, $sql."SELECT pg_catalog.setval('public.landmarks_id_seq', 1, true);\n");

    return $file;
}

/**
 * @return array<string, list<array<string, mixed>>>
 */
function mapSnapshot(): array
{
    return collect(Backup::MAP_TABLES)->mapWithKeys(fn (string $table) => [
        $table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ])->all();
}

/**
 * Tuzatilgan xarita: hudud (ota-hudud jismoniy tartibda bolasidan keyin), tahrirlangan OSM mo'ljali,
 * qo'lda qo'shilgan mo'ljal, o'chirilgan mo'ljal va qayta nomlangan ko'cha.
 *
 * @return array{city: int, edited: int, manual: int, deleted: int, user: int}
 */
function seedEditedMap(): array
{
    $user = User::factory()->create()->id;
    $province = DB::table('regions')->insertGetId(['type' => 'region', 'name_uz' => "Farg'ona viloyati", 'osm_id' => 1001, 'created_at' => now(), 'updated_at' => now()]);
    $city = DB::table('regions')->insertGetId(['parent_id' => $province, 'type' => 'city', 'name_uz' => "Farg'ona", 'name_ru' => 'Фергана', 'osm_id' => 1002,
        'center' => DB::raw("ST_GeogFromText('POINT(71.78 40.38)')"), 'created_at' => now(), 'updated_at' => now()]);
    // Yangilangan qator jadval oxiriga ko'chadi — COPY'da ota-hudud bolasidan keyin keladi
    DB::table('regions')->where('id', $province)->update(['name_ru' => 'Ферганская область']);

    $landmark = fn (array $attrs) => DB::table('landmarks')->insertGetId([
        'region_id' => $city, 'location' => DB::raw("ST_GeogFromText('POINT(71.79 40.39)')"), 'created_at' => now(), 'updated_at' => now(), ...$attrs,
    ]);
    $edited = $landmark(['name' => 'Mustaqillik ko\'chasi', 'search' => 'mustaqillik kochasi', 'osm_id' => 2001, 'source' => 'osm',
        'usage_count' => 7, 'edited_at' => now()->subDay(), 'aliases' => json_encode(["Lenin ko'chasi"])]);
    $manual = $landmark(['name' => "Yangi bozor\tdarvoza", 'search' => 'yangi bozor', 'source' => 'manual', 'created_by' => $user, 'usage_count' => 3]);
    $deleted = $landmark(['name' => 'Yopilgan do\'kon', 'osm_id' => 2003]);
    DB::table('landmarks')->where('id', $deleted)->delete();

    DB::table('street_renames')->insert(['old_name' => 'Lenin', 'new_name' => 'Mustaqillik', 'region_id' => $city, 'created_by' => $user, 'created_at' => now(), 'updated_at' => now()]);

    return ['city' => $city, 'edited' => $edited, 'manual' => $manual, 'deleted' => $deleted, 'user' => $user];
}

test('map data moves to a fresh server exactly as edited, without the old firm\'s users and statistics', function () {
    $ids = seedEditedMap();
    $before = mapSnapshot();
    $sql = mapSqlFromDatabase();

    // Yangi server: OSM importi o'chirilgan mo'ljalni qayta qo'shgan bo'lsa ham u ketadi
    foreach (array_reverse(Backup::MAP_TABLES) as $table) {
        DB::table($table)->delete();
    }
    DB::table('landmarks')->insert(['name' => 'Yopilgan do\'kon', 'osm_id' => 2003, 'location' => DB::raw("ST_GeogFromText('POINT(71.7 40.3)')")]);

    try {
        $counts = app(Backup::class)->importMapSql($sql);
    } finally {
        File::delete($sql);
    }

    $after = mapSnapshot();
    $strip = fn (array $rows, array $columns) => array_map(fn (array $row) => array_diff_key($row, array_flip($columns)), $rows);

    expect($counts)->toBe(['regions' => 2, 'landmarks' => 2, 'street_renames' => 1])
        ->and($after['regions'])->toBe($before['regions'])
        ->and($strip($after['landmarks'], ['created_by', 'usage_count']))->toBe($strip($before['landmarks'], ['created_by', 'usage_count']))
        ->and($strip($after['street_renames'], ['created_by']))->toBe($strip($before['street_renames'], ['created_by']))
        ->and(array_column($after['landmarks'], 'created_by'))->toBe([null, null])
        ->and(array_column($after['landmarks'], 'usage_count'))->toBe([0, 0])
        ->and($after['street_renames'][0]['created_by'])->toBeNull()
        ->and($after['street_renames'][0]['region_id'])->toBe($ids['city'])
        ->and(DB::table('landmarks')->where('id', $ids['edited'])->value('edited_at'))->not->toBeNull()
        ->and(DB::table('landmarks')->where('id', $ids['manual'])->value('name'))->toBe("Yangi bozor\tdarvoza")
        ->and(DB::table('landmarks')->where('osm_id', 2003)->exists())->toBeFalse();

    // Sequence ko'chirilgan ID'lardan keyin davom etadi
    $new = DB::table('landmarks')->insertGetId(['name' => 'Yangi', 'location' => DB::raw("ST_GeogFromText('POINT(71.7 40.3)')")]);
    $region = DB::table('regions')->insertGetId(['type' => 'city', 'name_uz' => 'Marg\'ilon']);
    expect($new)->toBeGreaterThan(max($ids['edited'], $ids['manual']))
        ->and($region)->toBeGreaterThan($ids['city']);
});

test('map import refuses when a working server already references the map, keeps references with --force', function () {
    $ids = seedEditedMap();
    $branch = Branch::factory()->create(['region_id' => $ids['city']]);
    $sql = mapSqlFromDatabase();
    $backup = app(Backup::class);

    try {
        expect(fn () => $backup->importMapSql($sql))->toThrow(RuntimeException::class, 'branches.region_id: 1');
        expect(DB::table('landmarks')->count())->toBe(2)
            ->and($branch->fresh()?->region_id)->toBe($ids['city']);

        $backup->importMapSql($sql, force: true);
        expect($branch->fresh()?->region_id)->toBe($ids['city'])
            ->and(DB::table('landmarks')->count())->toBe(2);
    } finally {
        File::delete($sql);
    }
});

test('map import accepts only map tables', function () {
    $sql = tempnam(sys_get_temp_dir(), 'map-sql');
    File::put($sql, "COPY public.users (id, name) FROM stdin;\n1\tx\n\\.\n");

    try {
        expect(fn () => app(Backup::class)->importMapSql($sql))->toThrow(RuntimeException::class, 'begona jadval: users');
    } finally {
        File::delete($sql);
    }
});

test('dtaxi:map-import converts the dump with pg_restore and loads it', function () {
    seedEditedMap();
    $sql = mapSqlFromDatabase();
    foreach (array_reverse(Backup::MAP_TABLES) as $table) {
        DB::table($table)->delete();
    }
    $dump = tempnam(sys_get_temp_dir(), 'map-dump');
    $commands = [];
    Process::fake(['*pg_restore*' => function (PendingProcess $process) use ($sql, &$commands) {
        $commands[] = $process->command;
        $out = substr((string) collect($process->command)->first(fn (string $arg) => str_starts_with($arg, '--file=')), 7);
        File::copy($sql, $out);

        return Process::result();
    }]);

    try {
        $this->artisan('dtaxi:map-import', ['file' => $dump])
            ->expectsConfirmation("Bazadagi hududlar, mo'ljallar va ko'cha nomlari {$dump} dagisiga almashtiriladi. Davom etilsinmi?", 'yes')
            ->expectsOutputToContain("Tayyor: 2 hudud, 2 mo'ljal, 1 ko'cha nomi.")
            ->assertSuccessful();
        expect($commands[0])->toContain('--data-only')
            ->and(File::exists($dump.'.sql'))->toBeFalse();
    } finally {
        File::delete([$sql, $dump]);
    }
});
