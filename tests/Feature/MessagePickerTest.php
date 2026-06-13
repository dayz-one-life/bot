<?php

use App\Services\Personality\MessagePicker;

it('interpolates tokens into the chosen line', function () {
    config()->set('personality.t_interp', ['hello :name, you have :n token(s)']);
    $picker = new MessagePicker(fn (array $pool, ?int $avoid) => 0);

    expect($picker->pick('t_interp', [':name' => 'Bob', ':n' => 3]))
        ->toBe('hello Bob, you have 3 token(s)');
});

it('returns a member of the pool', function () {
    config()->set('personality.t_member', ['a', 'b', 'c']);
    $picker = new MessagePicker(fn (array $pool, ?int $avoid) => 1);

    expect($picker->pick('t_member'))->toBe('b');
});

it('never repeats the immediately-previous line (default chooser, 2-line pool)', function () {
    config()->set('personality.t_norepeat', ['one', 'two']);
    $picker = new MessagePicker(); // real default chooser

    $prev = null;
    for ($i = 0; $i < 12; $i++) {
        $line = $picker->pick('t_norepeat');
        expect($line)->not->toBe($prev);
        $prev = $line;
    }
});

it('falls back to the provided string when the pool is missing', function () {
    $picker = new MessagePicker();
    expect($picker->pick('t_absent', [':x' => 'Y'], 'fallback :x here'))
        ->toBe('fallback Y here');
});

it('returns empty string when the pool is missing and no fallback given', function () {
    $picker = new MessagePicker();
    expect($picker->pick('t_absent_2'))->toBe('');
});
