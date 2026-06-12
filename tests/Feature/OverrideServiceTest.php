<?php

use App\Models\AssociateOverride;
use App\Models\Player;
use App\Services\Bounty\OverrideService;

beforeEach(fn () => $this->svc = new OverrideService());

function mkPlayer(string $tag): Player {
    return Player::create(['gamertag' => $tag, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}

it('sets a normalized override row (a_id < b_id) once', function () {
    $a = mkPlayer('A'); $b = mkPlayer('B');
    expect($this->svc->set('B', 'A', true))->toBe('ok'); // reversed order on input
    $row = AssociateOverride::first();
    expect($row->player_a_id)->toBe(min($a->id, $b->id));
    expect($row->player_b_id)->toBe(max($a->id, $b->id));
    expect($row->force)->toBeTrue();

    // updating the same pair flips force without duplicating
    expect($this->svc->set('A', 'B', false))->toBe('ok');
    expect(AssociateOverride::count())->toBe(1);
    expect(AssociateOverride::first()->force)->toBeFalse();
});

it('reports a missing gamertag', function () {
    mkPlayer('A');
    expect($this->svc->set('A', 'Ghost', true))->toBe('not_found');
});

it('clears an override', function () {
    mkPlayer('A'); mkPlayer('B');
    $this->svc->set('A', 'B', true);
    expect($this->svc->clear('A', 'B'))->toBe('ok');
    expect(AssociateOverride::count())->toBe(0);
});
