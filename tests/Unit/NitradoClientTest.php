<?php

use App\Services\Nitrado\NitradoClient;
use Illuminate\Support\Facades\Http;

it('lists ADM files sorted oldest-first with parsed timestamps', function () {
    Http::fake([
        '*/gameservers' => Http::response(['status' => 'success', 'data' => ['gameserver' => [
            'game_specific' => ['path' => '/games/abc/ftproot/dayzxb/', 'log_files' => []],
        ]]]),
        '*/file_server/list*' => Http::response(['status' => 'success', 'data' => ['entries' => [
            ['name' => 'DayZServer_X1_x64_2026-06-10_01-00-00.ADM', 'path' => '/p/b.ADM', 'modified_at' => 1000],
            ['name' => 'DayZServer_X1_x64_2026-06-09_01-00-00.ADM', 'path' => '/p/a.ADM', 'modified_at' => 900],
            ['name' => 'ignore.txt', 'path' => '/p/ignore.txt'],
        ]]]),
    ]);

    $client = new NitradoClient('token', 123);
    $files = $client->listAdmFiles();

    expect($files)->toHaveCount(2);
    expect($files[0]['name'])->toBe('DayZServer_X1_x64_2026-06-09_01-00-00.ADM'); // oldest first
    expect($files[0]['timestamp'])->toBeInstanceOf(DateTimeImmutable::class);
    expect($files[0]['modifiedAt'])->toBe(900);
});

it('downloads a file by following the token url', function () {
    Http::fake([
        '*/file_server/download*' => Http::response(['status' => 'success', 'data' => ['token' => ['url' => 'https://dl.example/file']]]),
        'https://dl.example/file' => Http::response("line1\nline2"),
    ]);

    $client = new NitradoClient('token', 123);
    expect($client->downloadFile('/p/a.ADM'))->toBe("line1\nline2");
});
