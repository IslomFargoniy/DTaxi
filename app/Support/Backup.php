<?php

namespace App\Support;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Baza zaxirasi (pg_dump custom format). Kunlik schedule va har yangilanishdan oldin (Bosqich 3b).
 * Fayllar arxivi kunlik, suhbat yozuvlari esa alohida — har kuni faqat kechagi kun yozuvlari zaxira serveriga.
 */
class Backup
{
    /** Suhbat yozuvlari (ProcessRecording): recordings/{filial}/{Y}/{m}/{d}/{id}.mp3 */
    public const RECORDINGS = 'storage/app/private/recordings';

    /**
     * Bazadagi xarita qismi (qaror #65): yangi firmaga xarita to'plami bilan ko'chadi.
     * Tartib muhim — keyingisi oldingisiga bog'langan.
     */
    public const MAP_TABLES = ['regions', 'landmarks', 'street_renames'];

    /** Xarita to'plami ichidagi baza qismi (tar ildizida) */
    public const MAP_DUMP = 'map.dump';

    public function directory(): string
    {
        return storage_path('app/private/backups');
    }

    /**
     * Oxirgi yangilanish holati (dtaxi:update): commit va yangilanishdan oldingi zaxira.
     */
    public static function updateStateFile(): string
    {
        return storage_path('app/private/update-state.json');
    }

    public function create(string $label = 'daily'): string
    {
        File::ensureDirectoryExists($this->directory());
        $file = $this->directory().'/'.now()->format('Y-m-d_His')."_{$label}.dump";

        $result = Process::timeout(3600)->env($this->env())->run([
            'pg_dump', '--format=custom', '--no-owner', '--exclude-table-data=public.spatial_ref_sys', '--file='.$file, ...$this->connectionArgs(),
        ]);
        if ($result->failed()) {
            throw new RuntimeException('pg_dump: '.$result->errorOutput());
        }
        @chmod($file, 0600);

        return $file;
    }

    /**
     * Fayllar zaxirasi: haydovchi hujjatlari, ovozli bo'laklar, APK va `.env`.
     * `.env` dagi APP_KEY siz bazadagi shifrlangan kalitlarni (Click, Payme, SIP) tiklab bo'lmaydi.
     * Suhbat yozuvlari kiritilmaydi — ular oyiga GB'lab o'sadi va har kuni to'liq nusxalanmasligi kerak (createRecordings).
     */
    public function createFiles(string $label = 'daily'): string
    {
        File::ensureDirectoryExists($this->directory());
        $file = $this->directory().'/'.now()->format('Y-m-d_His')."_{$label}.files.tar.gz";

        $result = Process::timeout(3600)->path(base_path())->run([
            'tar', '--create', '--gzip', '--file='.$file,
            '--exclude=storage/app/private/backups',
            '--exclude='.self::RECORDINGS,
            '.env', 'storage/app/private',
        ]);
        if ($result->failed()) {
            throw new RuntimeException('tar: '.$result->errorOutput());
        }
        @chmod($file, 0600);

        return $file;
    }

    /**
     * Bir kunlik suhbat yozuvlari arxivi (MP3 allaqachon siqilgan — gzip'siz). Shu kun yozuvi bo'lmasa — null.
     * Kun tugagach yozuvlar o'zgarmaydi, shuning uchun har kuni faqat kechagi kun yuboriladi (o'sib boruvchi zaxira).
     */
    public function createRecordings(CarbonInterface $day, string $label = 'recordings'): ?string
    {
        $dirs = collect(File::glob(base_path(self::RECORDINGS).'/*/'.$day->format('Y/m/d'), GLOB_ONLYDIR))
            ->map(fn (string $dir) => ltrim(substr($dir, strlen(base_path())), '/'))
            ->all();
        if ($dirs === []) {
            return null;
        }

        File::ensureDirectoryExists($this->directory());
        $file = $this->directory().'/'.$day->format('Y-m-d')."_{$label}.tar";
        $result = Process::timeout(3600)->path(base_path())->run(['tar', '--create', '--file='.$file, ...$dirs]);
        if ($result->failed()) {
            throw new RuntimeException('tar: '.$result->errorOutput());
        }
        @chmod($file, 0600);

        return $file;
    }

    /**
     * Fayllar arxivini tiklash. `.env` ustidan yozilmaydi (yangi serverning baza paroli va domeni boshqa) —
     * faqat APP_KEY olinadi, aks holda bazadagi shifrlangan kalitlar o'qilmaydi; eski `.env` yoniga `.env.restored` bo'lib tushadi.
     *
     * @return bool APP_KEY almashtirildimi
     */
    public function restoreFiles(string $archive, ?string $root = null): bool
    {
        $root ??= base_path();
        $tmp = $root.'/storage/app/restore-tmp';
        File::deleteDirectory($tmp);
        File::ensureDirectoryExists($tmp);

        try {
            $result = Process::timeout(3600)->path($tmp)->run(['tar', '--extract', '--file='.$archive]);
            if ($result->failed()) {
                throw new RuntimeException('tar: '.$result->errorOutput());
            }

            if (File::isDirectory($tmp.'/storage/app/private')) {
                File::copyDirectory($tmp.'/storage/app/private', $root.'/storage/app/private');
            }

            if (! File::exists($tmp.'/.env')) {
                return false;
            }
            File::copy($tmp.'/.env', $root.'/.env.restored');
            @chmod($root.'/.env.restored', 0600);

            $key = $this->envValue((string) File::get($tmp.'/.env'), 'APP_KEY');
            $envFile = $root.'/.env';
            $env = File::exists($envFile) ? (string) File::get($envFile) : '';
            if ($key === null || $key === $this->envValue($env, 'APP_KEY')) {
                return false;
            }
            $line = 'APP_KEY='.$key;
            File::put($envFile, preg_match('/^APP_KEY=.*$/m', $env)
                ? (string) preg_replace('/^APP_KEY=.*$/m', addcslashes($line, '\\$'), $env)
                : rtrim($env)."\n".$line."\n");

            return true;
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    /**
     * Zaxira nusxasini firmaning boshqa serveriga (SFTP) yuborish; sozlanmagan bo'lsa — false.
     */
    public function upload(string $file): bool
    {
        $disk = $this->remoteDisk();
        if ($disk === null) {
            return false;
        }

        $stream = fopen($file, 'r');
        if ($stream === false) {
            throw new RuntimeException("Faylni o'qib bo'lmadi: {$file}");
        }
        try {
            return $disk->writeStream(basename($file), $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Zaxira serveridagi eski nusxalarni o'chirish — aks holda u yerda disk to'ladi.
     * Kun fayl nomidan olinadi (SFTP'dagi vaqt ko'chirishda o'zgarishi mumkin). Suhbat yozuvlari o'z muddati bilan
     * (calls.recordings_retention_days) — ular har kuni faqat bir marta yuboriladi, boshqa nusxasi yo'q.
     * Xarita to'plamidan oxirgi ikkitasi qoladi.
     */
    public function pruneRemote(int $keepDays, int $recordingsDays): int
    {
        $disk = $this->remoteDisk();
        if ($disk === null) {
            return 0;
        }

        $deleted = 0;
        $maps = [];
        foreach ($disk->files() as $path) {
            $name = basename($path);
            if (str_ends_with($name, '_map.tar')) {
                $maps[] = $path;

                continue;
            }
            if (preg_match('/^(\d{4}-\d{2}-\d{2})_/', $name, $m) !== 1) {
                continue;
            }
            $days = match (true) {
                str_ends_with($name, '_recordings.tar') => $recordingsDays,
                str_ends_with($name, '.dump'), str_ends_with($name, '.files.tar.gz') => $keepDays,
                default => null,
            };
            if ($days !== null && $m[1] < now()->subDays($days)->format('Y-m-d')) {
                $disk->delete($path);
                $deleted++;
            }
        }

        rsort($maps);
        foreach (array_slice($maps, 2) as $path) {
            $disk->delete($path);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Host kaliti barmoq izi (host_fingerprint, masalan `ssh-keyscan host | ssh-keygen -lf -` dagi SHA256:...) berilsa —
     * boshqa server o'zini zaxira serveri deb ko'rsatib baza nusxasini ola olmaydi.
     */
    protected function remoteDisk(): ?Filesystem
    {
        $credential = fn (string $key): string => (string) Integration::credential(IntegrationProvider::BackupServer, $key);
        $host = $credential('host');
        if ($host === '') {
            return null;
        }

        $fingerprint = trim($credential('host_fingerprint'));

        return Storage::build(array_filter([
            'driver' => 'sftp',
            'host' => $host,
            'port' => (int) ($credential('port') ?: 22),
            'username' => $credential('username'),
            'password' => $credential('password'),
            'root' => $credential('path') ?: '/',
            'hostFingerprint' => $fingerprint !== '' ? $fingerprint : null,
            'timeout' => 30,
            'throw' => true,
        ], fn ($value) => $value !== null));
    }

    public function restore(string $file): void
    {
        // Kengaytmalar (PostGIS, pg_trgm) va ularning jadvali (spatial_ref_sys) superuser'niki — ularni o'chirib-qayta yaratishga ruxsat yo'q, ro'yxatdan chiqariladi
        $list = Process::run(['pg_restore', '--list', $file]);
        if ($list->failed()) {
            throw new RuntimeException('pg_restore --list: '.$list->errorOutput());
        }
        $listFile = $file.'.list';
        File::put($listFile, collect(explode("\n", $list->output()))
            ->reject(fn (string $line) => str_contains($line, ' EXTENSION ') || str_contains($line, 'spatial_ref_sys'))
            ->implode("\n"));

        // --clean faqat zaxirada bor narsani o'chiradi: yangi versiya migratsiyasi qo'shgan jadval qolib ketsa,
        // eski kod bilan keyingi `migrate` "already exists" bilan to'xtaydi (--rollback)
        $this->dropObjectsMissingFrom($list->output());

        $result = Process::timeout(3600)->env($this->env())->run([
            'pg_restore', '--clean', '--if-exists', '--no-owner', '--single-transaction', '--use-list='.$listFile,
            ...$this->connectionArgs(dbFlag: true), $file,
        ]);
        File::delete($listFile);
        if ($result->failed()) {
            throw new RuntimeException('pg_restore: '.$result->errorOutput());
        }
    }

    /**
     * public sxemadagi zaxira ro'yxatida (pg_restore --list) yo'q jadval, ko'rinish, sequence va turlarni o'chirish.
     * Kengaytmalarga tegishli obyektlar (PostGIS: spatial_ref_sys, geometry turlari) tegilmaydi.
     *
     * @return list<string> o'chirilganlar
     */
    public function dropObjectsMissingFrom(string $toc): array
    {
        $listed = [];
        foreach (explode("\n", $toc) as $line) {
            if (preg_match('/^\d+; \d+ \d+ (TABLE|VIEW|MATERIALIZED VIEW|SEQUENCE|TYPE|DOMAIN) public (\S+) /', $line, $m) === 1) {
                $listed[$m[1] === 'DOMAIN' ? 'TYPE' : $m[1]][$m[2]] = true;
            }
        }
        // Ro'yxatda bitta ham jadval bo'lmasa — bu baza zaxirasi emas, hech narsa o'chirilmaydi
        if (($listed['TABLE'] ?? []) === []) {
            return [];
        }

        /** @var list<object{kind: string, name: string}> $objects */
        $objects = DB::select(<<<'SQL'
            SELECT CASE c.relkind WHEN 'r' THEN 'TABLE' WHEN 'p' THEN 'TABLE' WHEN 'v' THEN 'VIEW'
                       WHEN 'm' THEN 'MATERIALIZED VIEW' ELSE 'SEQUENCE' END AS kind, c.relname AS name
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm', 'S')
              AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype = 'e')
              -- jadvalga tegishli (serial) sequence jadval bilan birga o'chadi
              AND NOT (c.relkind = 'S' AND EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype IN ('a', 'i')))
            UNION ALL
            SELECT 'TYPE', t.typname
            FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
            WHERE n.nspname = 'public' AND t.typtype IN ('e', 'd', 'c', 'r')
              AND (t.typrelid = 0 OR (SELECT relkind FROM pg_class WHERE oid = t.typrelid) = 'c')
              AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_type'::regclass AND d.objid = t.oid AND d.deptype = 'e')
            SQL);

        $dropped = [];
        DB::transaction(function () use ($objects, $listed, &$dropped) {
            // Avval jadval va ko'rinishlar — turlar ularga bog'langan bo'lishi mumkin
            foreach (['MATERIALIZED VIEW', 'VIEW', 'TABLE', 'SEQUENCE', 'TYPE'] as $kind) {
                foreach ($objects as $object) {
                    if ($object->kind !== $kind || isset($listed[$kind][$object->name])) {
                        continue;
                    }
                    DB::statement("DROP {$kind} IF EXISTS public.\"".str_replace('"', '""', $object->name).'" CASCADE');
                    $dropped[] = "{$kind} {$object->name}";
                }
            }
        });

        return $dropped;
    }

    /**
     * Xarita to'plami: tile'lar va shriftlar (public/map), OSM eksportlari (storage/app/osm) va OSRM marshrut
     * ma'lumoti — yangi serverda internetdan bir necha soat qayta yasamasdan tiklash uchun (install.sh --map-bundle).
     * Bazadagi xarita qismi (hududlar, tuzatilgan mo'ljallar, ko'cha nomlari) — map.dump (qaror #65).
     * Fayllar allaqachon siqilgan — gzip'siz. Xarita tayyorlanmagan bo'lsa — null.
     */
    public function createMap(?string $root = null): ?string
    {
        $root ??= base_path();
        if (! File::exists($root.'/public/map/region.pmtiles')) {
            return null;
        }

        File::ensureDirectoryExists($this->directory());
        $file = $this->directory().'/'.now()->format('Y-m-d_His').'_map.tar';
        // .bak — qo'lda almashtirishdan oldingi nusxalar, to'plamga kirmaydi
        $args = ['tar', '--create', '--file='.$file, '--exclude=*.bak', '-C', $root, 'public/map'];
        if (File::isDirectory($root.'/storage/app/osm')) {
            $args[] = 'storage/app/osm';
        }
        $osrm = (string) config('services.osrm.data_dir');
        if ($osrm !== '' && File::isDirectory($osrm) && basename($osrm) === 'osrm') {
            array_push($args, '-C', dirname($osrm), 'osrm');
        }

        $tmp = $this->directory().'/map-export-tmp';
        File::deleteDirectory($tmp);
        File::ensureDirectoryExists($tmp);
        try {
            $this->dumpMapData($tmp.'/'.self::MAP_DUMP);
            array_push($args, '-C', $tmp, self::MAP_DUMP);

            $result = Process::timeout(3600)->run($args);
            if ($result->failed()) {
                File::delete($file);
                throw new RuntimeException('tar: '.$result->errorOutput());
            }
        } finally {
            File::deleteDirectory($tmp);
        }
        @chmod($file, 0600);

        // Katta fayl (~1 GB) — mahalliy faqat eng oxirgisi
        foreach (File::glob($this->directory().'/*_map.tar') as $old) {
            if ($old !== $file) {
                File::delete($old);
            }
        }

        return $file;
    }

    /**
     * Faqat xarita jadvallari ma'lumoti (sxemasiz — yangi serverda migratsiyalar yaratadi). ID'lar saqlanadi.
     */
    public function dumpMapData(string $file): void
    {
        $tables = array_map(fn (string $table) => '--table=public.'.$table, self::MAP_TABLES);
        $result = Process::timeout(3600)->env($this->env())->run([
            'pg_dump', '--format=custom', '--data-only', '--no-owner', ...$tables, '--file='.$file, ...$this->connectionArgs(),
        ]);
        if ($result->failed()) {
            throw new RuntimeException('pg_dump: '.$result->errorOutput());
        }
        @chmod($file, 0600);
    }

    /**
     * Xarita to'plamidagi map.dump'ni yuklash: pg_restore uni SQL (COPY) ga o'giradi, yuklash esa shu ulanishda
     * bitta tranzaksiyada — xato bo'lsa eski xarita joyida qoladi.
     *
     * @return array<string, int> jadval => yozuvlar soni
     */
    public function importMapDump(string $dump, bool $force = false): array
    {
        $sql = $dump.'.sql';
        $result = Process::timeout(3600)->run(['pg_restore', '--data-only', '--file='.$sql, $dump]);
        try {
            if ($result->failed()) {
                throw new RuntimeException('pg_restore: '.$result->errorOutput());
            }

            return $this->importMapSql($sql, $force);
        } finally {
            File::delete($sql);
        }
    }

    /**
     * pg_restore --data-only chiqargan SQL'dagi COPY bloklari — faqat xarita jadvallari qabul qilinadi.
     * Avval vaqtinchalik jadvalga, keyin asosiysiga: regions.parent_id o'ziga bog'langan va qatorlar tartibi
     * ota-hudud avval kelishini kafolatlamaydi. Boshqa firma foydalanuvchilariga bog'lanishlar (created_by) va
     * statistika (usage_count) olinmaydi; tahrir belgisi (edited_at) saqlanadi — keyingi OSM importi uni buzmaydi.
     *
     * @param  bool  $force  boshqa jadvallar (filial hududi, mijoz manzili) xarita yozuvlariga bog'langan bo'lsa ham:
     *                       ID saqlangani uchun yangi xaritada bor bog'lanishlar tiklanadi, qolganlari NULL
     * @return array<string, int>
     */
    public function importMapSql(string $sqlFile, bool $force = false): array
    {
        $handle = fopen($sqlFile, 'r');
        if ($handle === false) {
            throw new RuntimeException("Faylni o'qib bo'lmadi: {$sqlFile}");
        }

        try {
            return DB::transaction(function () use ($handle, $force) {
                $foreign = $this->mapForeignKeys();
                $inbound = array_values(array_filter($foreign, fn (array $fk) => ! in_array($fk['table'], self::MAP_TABLES, true)));
                $saved = $this->detachMapReferences($inbound, $force);

                foreach (array_reverse(self::MAP_TABLES) as $table) {
                    DB::statement("DELETE FROM {$table}");
                }
                foreach (self::MAP_TABLES as $table) {
                    DB::statement("CREATE TEMP TABLE map_import_{$table} (LIKE {$table} INCLUDING DEFAULTS) ON COMMIT DROP");
                }

                $this->copyMapRows($handle);

                // Xarita ichidan tashqariga bog'lanishlar (created_by → users) — yangi serverda bu foydalanuvchilar yo'q
                $override = ['regions' => ['parent_id' => 'NULL'], 'landmarks' => ['usage_count' => '0']];
                foreach ($foreign as $fk) {
                    if (in_array($fk['table'], self::MAP_TABLES, true) && ! in_array($fk['references'], self::MAP_TABLES, true)) {
                        $override[$fk['table']][$fk['column']] = 'NULL';
                    }
                }

                $counts = [];
                foreach (self::MAP_TABLES as $table) {
                    $columns = Schema::getColumnListing($table);
                    $select = array_map(fn (string $column) => ($override[$table][$column] ?? '"'.$column.'"').' AS "'.$column.'"', $columns);
                    $counts[$table] = DB::affectingStatement('INSERT INTO '.$table.' ("'.implode('", "', $columns).'") SELECT '.implode(', ', $select)." FROM map_import_{$table}");

                    if ($table === 'regions') {
                        DB::statement('UPDATE regions SET parent_id = s.parent_id FROM map_import_regions s WHERE s.id = regions.id AND s.parent_id IS NOT NULL');
                    }
                    // Keyingi qo'lda qo'shilgan yozuv ko'chirilgan ID bilan to'qnashmasin
                    DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE(MAX(id), 1), MAX(id) IS NOT NULL) FROM {$table}");
                }

                foreach ($saved as $i => $fk) {
                    DB::statement("UPDATE {$fk['table']} t SET \"{$fk['column']}\" = r.ref FROM map_import_ref_{$i} r
                        WHERE t.id = r.id AND EXISTS (SELECT 1 FROM {$fk['references']} m WHERE m.id = r.ref)");
                    DB::statement("DROP TABLE map_import_ref_{$i}");
                }
                foreach (self::MAP_TABLES as $table) {
                    DB::statement("DROP TABLE map_import_{$table}");
                }

                return $counts;
            });
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function copyMapRows($handle): void
    {
        $pdo = DB::connection()->getPdo();
        $columnsOf = [];
        while (($line = fgets($handle)) !== false) {
            if (preg_match('/^COPY (?:"?public"?\.)?"?([a-z_]+)"? \((.*)\) FROM stdin;$/', rtrim($line, "\n"), $m) !== 1) {
                continue;
            }
            $table = $m[1];
            if (! in_array($table, self::MAP_TABLES, true)) {
                throw new RuntimeException("Xarita to'plamida begona jadval: {$table}");
            }
            $columnsOf[$table] ??= Schema::getColumnListing($table);
            $columns = array_map(fn (string $column) => trim($column, ' "'), explode(',', $m[2]));
            if (($unknown = array_diff($columns, $columnsOf[$table])) !== []) {
                // Eski/yangi versiya — avval ikkala serverni bir xil versiyaga yangilash kerak
                throw new RuntimeException("{$table}: bazada yo'q ustunlar (".implode(', ', $unknown).') — serverlar versiyasi mos emas');
            }
            $fields = '"'.implode('", "', $columns).'"';

            $rows = [];
            while (($row = fgets($handle)) !== false && ($row = rtrim($row, "\n")) !== '\.') {
                $rows[] = $row;
                if (count($rows) === 5000) {
                    $this->copyRows($pdo, "map_import_{$table}", $rows, $fields);
                    $rows = [];
                }
            }
            $this->copyRows($pdo, "map_import_{$table}", $rows, $fields);
        }
    }

    /**
     * @param  list<string>  $rows  COPY text formatidagi qatorlar
     */
    private function copyRows(\PDO $pdo, string $table, array $rows, string $fields): void
    {
        if ($rows !== [] && ! $pdo->pgsqlCopyFromArray($table, $rows, "\t", '\\\\N', $fields)) {
            throw new RuntimeException("COPY {$table}: ".implode(' ', $pdo->errorInfo()));
        }
    }

    /**
     * Xarita jadvallariga yoki ulardan chiqadigan bir ustunli tashqi kalitlar (bazadan — keyingi migratsiyalar ham hisobga olinadi).
     *
     * @return list<array{table: string, column: string, references: string}>
     */
    private function mapForeignKeys(): array
    {
        $tables = "'".implode("', '", self::MAP_TABLES)."'";
        /** @var list<object{table: string, column: string, references: string}> $rows */
        $rows = DB::select(<<<SQL
            SELECT c.conrelid::regclass::text AS "table", a.attname AS "column", c.confrelid::regclass::text AS "references"
            FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
            WHERE c.contype = 'f' AND (c.conrelid::regclass::text IN ({$tables}) OR c.confrelid::regclass::text IN ({$tables}))
            ORDER BY 1, 2
            SQL);

        return array_map(fn (object $row) => ['table' => $row->table, 'column' => $row->column, 'references' => $row->references], $rows);
    }

    /**
     * @param  list<array{table: string, column: string, references: string}>  $inbound
     * @return array<int, array{table: string, column: string, references: string}> vaqtinchalik saqlangan bog'lanishlar
     */
    private function detachMapReferences(array $inbound, bool $force): array
    {
        $used = [];
        foreach ($inbound as $fk) {
            $count = DB::table($fk['table'])->whereNotNull($fk['column'])->count();
            if ($count > 0) {
                $used[] = "{$fk['table']}.{$fk['column']}: {$count}";
            }
        }
        if ($used !== [] && ! $force) {
            throw new RuntimeException('Boshqa jadvallar mavjud xarita yozuvlariga bog\'langan ('.implode(', ', $used).'). '
                .'Xarita faqat yangi serverga yuklanadi; baribir almashtirish uchun --force');
        }

        $saved = [];
        foreach ($inbound as $i => $fk) {
            // ON DELETE CASCADE bo'lsa ham bog'langan yozuvlar o'chib ketmasin — avval NULL
            if (Schema::hasColumn($fk['table'], 'id')) {
                DB::statement("CREATE TEMP TABLE map_import_ref_{$i} ON COMMIT DROP AS SELECT id, \"{$fk['column']}\" AS ref FROM {$fk['table']} WHERE \"{$fk['column']}\" IS NOT NULL");
                $saved[$i] = $fk;
            }
            DB::table($fk['table'])->whereNotNull($fk['column'])->update([$fk['column'] => null]);
        }

        return $saved;
    }

    /**
     * Muddati o'tgan zaxiralarni o'chirish. Oxirgi yangilanishdan oldingi zaxira (--rollback uchun) keyingi yangilanishgacha saqlanadi.
     */
    public function prune(int $keepDays = 14): int
    {
        $deleted = 0;
        $keep = $this->lastUpdateBackup();
        $files = [...File::glob($this->directory().'/*.dump'), ...File::glob($this->directory().'/*.files.tar.gz'), ...File::glob($this->directory().'/*_recordings.tar')];
        foreach ($files as $file) {
            if ($file === $keep) {
                continue;
            }
            if (filemtime($file) < now()->subDays($keepDays)->getTimestamp()) {
                File::delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function lastUpdateBackup(): ?string
    {
        if (! File::exists(self::updateStateFile())) {
            return null;
        }
        $state = json_decode((string) File::get(self::updateStateFile()), true);

        return is_array($state) && is_string($state['backup'] ?? null) ? $state['backup'] : null;
    }

    private function envValue(string $env, string $key): ?string
    {
        return preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $m) && trim($m[1]) !== '' ? trim($m[1]) : null;
    }

    /**
     * @return list<string>
     */
    private function connectionArgs(bool $dbFlag = false): array
    {
        /** @var array{host: string, port: string|int, username: string, database: string} $c */
        $c = config('database.connections.'.config('database.default'));

        return [
            '--host='.$c['host'],
            '--port='.$c['port'],
            '--username='.$c['username'],
            ($dbFlag ? '--dbname=' : '').$c['database'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function env(): array
    {
        return ['PGPASSWORD' => (string) config('database.connections.'.config('database.default').'.password')];
    }
}
