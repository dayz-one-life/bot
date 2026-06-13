<?php

use App\SlashCommands\Concerns\RenamesToGamertag;

it('caps the nickname at 32 characters', function () {
    $obj = new class { use RenamesToGamertag { nicknameForGamertag as public; } };
    expect($obj->nicknameForGamertag('ShortTag'))->toBe('ShortTag');
    $long = str_repeat('a', 40);
    expect(mb_strlen($obj->nicknameForGamertag($long)))->toBe(32);
});
