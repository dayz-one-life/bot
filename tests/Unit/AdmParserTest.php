<?php

use App\Services\Adm\AdmParser;

beforeEach(fn () => $this->parser = new AdmParser());

it('parses a connect line', function () {
    $r = $this->parser->parseConnect('01:02:03 | Player "Alice" (id=ABC123=) is connected');
    expect($r)->toBe(['gamertag' => 'Alice', 'id' => 'ABC123=']);
});

it('parses a disconnect line', function () {
    $r = $this->parser->parseDisconnect('01:02:03 | Player "Bob" (id=XYZ=) has been disconnected');
    expect($r)->toBe(['gamertag' => 'Bob', 'id' => 'XYZ=']);
});

it('parses a PvP death with weapon and distance', function () {
    $line = '10:00:00 | Player "Victim" (DEAD) (id=V=) killed by Player "Killer" (id=K=) with M4A1 from 153.4 meters';
    $r = $this->parser->parseDeath($line);
    expect($r['victim'])->toBe('Victim');
    expect($r['cause'])->toBe('pvp');
    expect($r['killer'])->toBe('Killer');
    expect($r['weapon'])->toBe('M4A1');
    expect($r['distance'])->toBe(153.4);
});

it('parses environmental and self deaths', function () {
    expect($this->parser->parseDeath('10:00:00 | Player "A" (DEAD) (id=A=) bled out')['cause'])->toBe('bled_out');
    expect($this->parser->parseDeath('10:00:00 | Player "A" (DEAD) (id=A=) drowned')['cause'])->toBe('drowned');
    expect($this->parser->parseDeath('10:00:00 | Player "A" (DEAD) (id=A=) committed suicide')['cause'])->toBe('suicide');
    expect($this->parser->parseDeath('10:00:00 | Player "A" (DEAD) (id=A=) died.')['cause'])->toBe('died');
    expect($this->parser->parseDeath('10:00:00 | Player "A" (DEAD) (id=A=) killed by FallDamage')['cause'])->toBe('environment');
});

it('parses a PvP death with no distance', function () {
    $line = '10:00:00 | Player "Victim" (DEAD) (id=V=) killed by Player "Killer" (id=K=) with Knife';
    $r = $this->parser->parseDeath($line);
    expect($r['cause'])->toBe('pvp');
    expect($r['weapon'])->toBe('Knife');
    expect($r['distance'])->toBeNull();
});

it('parses a PvP death for a multi-word player name', function () {
    $line = '10:00:00 | Player "John Doe" (DEAD) (id=V=) killed by Player "Jane Smith" (id=K=) with M4A1 from 12.0 meters';
    $r = $this->parser->parseDeath($line);
    expect($r['victim'])->toBe('John Doe');
    expect($r['killer'])->toBe('Jane Smith');
});

it('classifies an unrecognized death tail as unknown', function () {
    expect($this->parser->parseDeath('10:00:00 | Player "A" (DEAD) (id=A=) vanished mysteriously')['cause'])->toBe('unknown');
});

it('ignores non-fatal hit lines', function () {
    $line = '10:00:00 | Player "A" (id=A=)[HP: 50] hit by Player "B" (id=B=) into Torso';
    expect($this->parser->parseDeath($line))->toBeNull();
    expect($this->parser->parseConnect($line))->toBeNull();
});

it('ignores a fatal hit line so the death is only counted once', function () {
    $line = '10:00:00 | Player "A" (DEAD) (id=A=)[HP: 0] hit by Player "B" (id=B=) into Head';
    expect($this->parser->parseDeath($line))->toBeNull();
});

it('detects a server boot header timestamp', function () {
    $r = $this->parser->parseBoot('AdminLog started on 2026-06-11 at 14:30:00');
    expect($r)->toBe('2026-06-11 14:30:00');
});

it('assigns timestamps from the header and bumps a day at midnight', function () {
    $lines = [
        'AdminLog started on 2026-06-11 at 23:59:00',
        '23:59:30 | Player "A" (id=A=) is connected',
        '00:00:30 | Player "A" (id=A=) has been disconnected',
    ];
    $fallback = new DateTimeImmutable('2026-06-11T00:00:00Z');
    $ts = $this->parser->assignTimestamps($lines, $fallback);

    expect($ts[0])->toBeNull();                       // header line is not an event
    expect($ts[1])->toBe(strtotime('2026-06-11T23:59:30Z') * 1000);
    expect($ts[2])->toBe(strtotime('2026-06-12T00:00:30Z') * 1000); // bumped a day
});

it('reads a non-UTC fallback date in UTC', function () {
    // 23:30 EDT on Jun 11 = 03:30 UTC on Jun 12, so the UTC calendar day is the 12th.
    $lines = ['00:01:00 | Player "A" (id=A=) is connected'];
    $fallback = new DateTimeImmutable('2026-06-11T23:30:00', new DateTimeZone('America/New_York'));
    $ts = $this->parser->assignTimestamps($lines, $fallback);
    expect($ts[0])->toBe(strtotime('2026-06-12T00:01:00Z') * 1000);
});

it('derives the clock offset as the minimum modified_at minus filename time, snapped to 15 min', function () {
    $files = [
        ['timestamp' => new DateTimeImmutable('2026-06-11T10:00:00Z'), 'modifiedAt' => strtotime('2026-06-11T15:00:05Z')],
        ['timestamp' => new DateTimeImmutable('2026-06-11T11:00:00Z'), 'modifiedAt' => strtotime('2026-06-11T16:20:00Z')],
    ];
    // min candidate ~5h -> snaps to 5h = 18000000 ms
    expect($this->parser->deriveClockOffsetMs($files))->toBe(18000000);
});
