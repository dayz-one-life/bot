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

it('extracts weapon and distance from a realistic kill line (pos=<> inside both id-parens)', function () {
    // Real ADM kill lines embed pos=<x, y, z> inside both players' (id=...) parens. The weapon and
    // distance must still be captured from the tail after the killer's closing paren — a regression
    // guard for KILL_RE / WEAPON_RE so the longest-distance leaderboard + newspaper furthest_kill work.
    $line = '10:00:00 | Player "Victim" (DEAD) (id=ABC pos=<7404.1, 3229.9, 6.1>) killed by Player "Killer" (id=XYZ pos=<7500.0, 3300.0, 6.0>) with M4A1 from 153.4 meters';
    $r = $this->parser->parseDeath($line);
    expect($r['cause'])->toBe('pvp');
    expect($r['killer'])->toBe('Killer');
    expect($r['weapon'])->toBe('M4A1');
    expect($r['distance'])->toBe(153.4);
});

it('attributes a killer but leaves weapon/distance null when the kill line has no weapon clause', function () {
    // Some PvP deaths log only "killed by Player X" with no "with <weapon>" tail; killer is still
    // attributed, but weapon/distance are legitimately null (this is why furthest_kill can be empty).
    $line = '10:00:00 | Player "Victim" (DEAD) (id=ABC pos=<1,2,3>) killed by Player "Killer" (id=XYZ pos=<4,5,6>)';
    $r = $this->parser->parseDeath($line);
    expect($r['cause'])->toBe('pvp');
    expect($r['killer'])->toBe('Killer');
    expect($r['weapon'])->toBeNull();
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

it('parses a standalone position line', function () {
    $r = $this->parser->parsePosition('12:34:56 | Player "Alice" (id=ABC123=) pos=<7500.5, 3200.1, 300.0>');
    expect($r)->toBe(['gamertag' => 'Alice', 'x' => 7500.5, 'y' => 3200.1]);
});

it('parses a position embedded inside the id parentheses', function () {
    $r = $this->parser->parsePosition('12:34:56 | Player "Bob" (id=XYZ= pos=<100.0, 200.0, 5.0>) is connected');
    expect($r)->toBe(['gamertag' => 'Bob', 'x' => 100.0, 'y' => 200.0]);
});

it('returns null when a line carries no position', function () {
    expect($this->parser->parsePosition('12:34:56 | Player "Bob" (id=XYZ=) is connected'))->toBeNull();
});

it('ignores a hit-by line for position sampling', function () {
    $line = '10:00:00 | Player "Victim" (id=V= pos=<10.0, 20.0, 1.0>)[HP: 50] hit by Player "Attacker" (id=A= pos=<11.0, 21.0, 1.0>) into Torso';
    expect($this->parser->parsePosition($line))->toBeNull();
});

it('records the first-named player and first position on a kill line', function () {
    $line = '10:00:00 | Player "Victim" (id=V= pos=<500.5, 600.5, 5.0>) killed by Player "Killer" (id=K= pos=<900.0, 950.0, 5.0>) with M4A1';
    $r = $this->parser->parsePosition($line);
    expect($r)->toBe(['gamertag' => 'Victim', 'x' => 500.5, 'y' => 600.5]);
});

it('parses negative coordinates', function () {
    $r = $this->parser->parsePosition('10:00:00 | Player "A" (id=A=) pos=<-500.0, -200.5, 10.0>');
    expect($r)->toBe(['gamertag' => 'A', 'x' => -500.0, 'y' => -200.5]);
});

it('rejects an off-map sentinel position (DayZ -FLT_MAX "unknown position")', function () {
    // DayZ writes ~ -FLT_MAX (a 39-digit decimal expansion) when a player's position is unknown.
    // Fed into distance math this produces astronomically wrong travel totals, so it must be dropped.
    $line = '12:34:56 | Player "Ghost" (id=G=) pos=<-340282346638528859811704183484516925440.000000, -340282346638528859811704183484516925440.000000, 0.000000>';
    expect($this->parser->parsePosition($line))->toBeNull();
});

it('keeps a valid in-bounds position near the map edge', function () {
    $r = $this->parser->parsePosition('12:34:56 | Player "Edge" (id=E=) pos=<15296.1, 15241.0, 3.0>');
    expect($r)->toBe(['gamertag' => 'Edge', 'x' => 15296.1, 'y' => 15241.0]);
});

it('parses a bunker entrance teleport line', function () {
    $line = '02:30:35 | Player "RonaldRaygun552" (id=89B90470 pos=<5154.0, 1075.1, 56.3>) was teleported from: <4767.4, 339.4, 10376.3> to: <5154.0, 56.3, 1075.1>. Reason: Spawning in Player Restricted Area: RestrictedAreaBunkerEntrance';
    expect($this->parser->parseBunkerEntrance($line))->toBe(['gamertag' => 'RonaldRaygun552']);
});

it('ignores a bunker exit teleport line', function () {
    $line = '03:01:32 | Player "RonaldRaygun552" (id=89B90470 pos=<4828.4, 10291.8, 339.9>) was teleported from: <5005.0, 17.7, 1086.6> to: <4828.4, 339.9, 10291.7>. Reason: Spawning in Player Restricted Area: RestrictedAreaBunkerExit';
    expect($this->parser->parseBunkerEntrance($line))->toBeNull();
});

it('ignores connect, death and bare position lines for bunker entrance', function () {
    expect($this->parser->parseBunkerEntrance('12:34:56 | Player "Bob" (id=XYZ pos=<100.0, 200.0, 5.0>) is connected'))->toBeNull();
    expect($this->parser->parseBunkerEntrance('12:34:56 | Player "Bob" (DEAD) (id=XYZ pos=<1,2,3>) killed by Player "Eve" (id=ABC)'))->toBeNull();
    expect($this->parser->parseBunkerEntrance('12:34:56 | Player "Bob" (id=XYZ pos=<100.0, 200.0, 5.0>)'))->toBeNull();
});

it('parses a player-vs-player hit', function () {
    $line = '10:00:00 | Player "Victim" (id=V= pos=<100.5, 200.0, 1.0>)[HP: 50] hit by Player "Attacker" (id=A= pos=<101.0, 201.0, 1.0>) into Torso';
    $h = $this->parser->parseHit($line);
    expect($h)->toMatchArray([
        'victim' => 'Victim',
        'victim_hp' => 50,
        'attacker_gamertag' => 'Attacker',
        'attacker_type' => 'player',
        'attacker_label' => null,
        'body_part' => 'Torso',
    ]);
    expect($h['victim_x'])->toBe(100.5);
    expect($h['victim_y'])->toBe(200.0);
});

it('parses an infected hit and humanizes the source', function () {
    $line = '10:00:00 | Player "Victim" (id=V= pos=<1.0, 2.0, 3.0>)[HP: 30] hit by ZmbM_JoggerSkinny_Red into Leg';
    $h = $this->parser->parseHit($line);
    expect($h['attacker_type'])->toBe('infected');
    expect($h['attacker_gamertag'])->toBeNull();
    expect($h['attacker_label'])->toBe('an infected jogger');
    expect($h['body_part'])->toBe('Leg');
});

it('classifies a real infected hit line (source token "Infected", not a Zmb class name)', function () {
    // Real ADM hit lines name the source as the word "Infected" with a trailing
    // "(N) for X damage (MeleeInfected)" suffix — unlike the kill line, which uses ZmbM_*.
    $line = '16:37:51 | Player "RonaldRaygun552" (id=89B90470 pos=<13426.5, 6308.3, 6.0>)[HP: 64.2] hit by Infected into Torso(1) for 6.175 damage (MeleeInfected)';
    $h = $this->parser->parseHit($line);
    expect($h['attacker_type'])->toBe('infected');
    expect($h['attacker_gamertag'])->toBeNull();
});

it('parses an animal hit', function () {
    $line = '10:00:00 | Player "Victim" (id=V=)[HP: 10] hit by Animal_UrsusArctos into Torso';
    $h = $this->parser->parseHit($line);
    expect($h['attacker_type'])->toBe('animal');
    expect($h['attacker_label'])->toBe('a bear');
    expect($h['victim_x'])->toBeNull();
});

it('parses an environmental hit', function () {
    $line = '10:00:00 | Player "Victim" (id=V=)[HP: 80] hit by FallDamage';
    $h = $this->parser->parseHit($line);
    expect($h['attacker_type'])->toBe('environment');
    expect($h['attacker_gamertag'])->toBeNull();
    expect($h['body_part'])->toBeNull();
});

it('returns null for a non-hit line', function () {
    expect($this->parser->parseHit('10:00:00 | Player "A" (id=A=) is connected'))->toBeNull();
});
