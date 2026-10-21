<?php

use Illuminate\Support\Facades\DB;

/**
 * Har bir vazifa alohida jarayonda (pcntl_fork) va alohida baza ulanishida bajariladi; hammasi bir paytda
 * boshlanishi uchun umumiy vaqtni kutadi. Natija — har vazifaning chiqish kodi:
 * 0 — vazifa true qaytardi, 1 — false yoki InvalidArgumentException (kutilgan rad), 2 — boshqa xato.
 *
 * @param  list<Closure(): bool>  $tasks
 * @return list<int>
 */
function inParallel(array $tasks): array
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl kerak');
    }

    $startAt = microtime(true) + 0.2;
    DB::disconnect();

    $pids = [];
    foreach ($tasks as $task) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::reconnect();
            // Ulanish ochilgandan keyin bir paytda — so'rovlar haqiqatan ham ustma-ust tushadi
            usleep(max(0, (int) (($startAt - microtime(true)) * 1_000_000)));
            try {
                $code = $task() ? 0 : 1;
            } catch (InvalidArgumentException) {
                $code = 1;
            } catch (Throwable $e) {
                fwrite(STDERR, $e::class.': '.$e->getMessage()."\n");
                $code = 2;
            }
            exit($code);
        }
        $pids[] = $pid;
    }

    $codes = [];
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $codes[] = pcntl_wexitstatus($status);
    }
    DB::reconnect();

    return $codes;
}
