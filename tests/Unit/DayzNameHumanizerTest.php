<?php

use App\Services\Adm\DayzNameHumanizer;

it('humanizes infected class names with their role', function () {
    expect(DayzNameHumanizer::text('killed by ZmbM_JoggerSkinny_Red'))
        ->toBe('killed by an infected jogger');
    expect(DayzNameHumanizer::text('killed by ZmbM_SoldierNormal'))
        ->toBe('killed by an infected soldier');
    expect(DayzNameHumanizer::text('killed by ZmbF_NurseFat with FistsBase'))
        ->toBe('killed by an infected nurse with FistsBase');
});

it('falls back to a generic infected when no role is parseable', function () {
    // suffix starts lowercase => no CamelCase role to extract
    expect(DayzNameHumanizer::text('killed by ZmbM_default'))->toBe('killed by an infected');
});

it('maps known animal species to friendly names, including color variants', function () {
    expect(DayzNameHumanizer::text('killed by Animal_UrsusArctos'))->toBe('killed by a bear');
    expect(DayzNameHumanizer::text('killed by Animal_CanisLupus_Grey'))->toBe('killed by a wolf'); // variant suffix
    expect(DayzNameHumanizer::text('killed by Animal_SusScrofa'))->toBe('killed by a wild boar');
});

it('maps unknown animal classes to a wild animal', function () {
    expect(DayzNameHumanizer::text('killed by Animal_Unknownus'))->toBe('killed by a wild animal');
});

it('leaves real player gamertags and weapons untouched', function () {
    expect(DayzNameHumanizer::text('killed by Player "Sniper_77" with M4A1 from 153 meters'))
        ->toBe('killed by Player "Sniper_77" with M4A1 from 153 meters');
});

it('is null-safe for the single-token convenience method', function () {
    expect(DayzNameHumanizer::token(null))->toBeNull();
    expect(DayzNameHumanizer::token('Sniper'))->toBe('Sniper');
    expect(DayzNameHumanizer::token('ZmbM_JoggerSkinny_Red'))->toBe('an infected jogger');
});
