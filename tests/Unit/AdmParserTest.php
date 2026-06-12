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

it('ignores non-fatal hit lines', function () {
    $line = '10:00:00 | Player "A" (id=A=)[HP: 50] hit by Player "B" (id=B=) into Torso';
    expect($this->parser->parseDeath($line))->toBeNull();
    expect($this->parser->parseConnect($line))->toBeNull();
});

it('ignores a fatal hit line so the death is only counted once', function () {
    $line = '10:00:00 | Player "A" (DEAD) (id=A=)[HP: 0] hit by Player "B" (id=B=) into Head';
    expect($this->parser->parseDeath($line))->toBeNull();
});
