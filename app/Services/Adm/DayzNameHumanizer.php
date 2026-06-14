<?php

namespace App\Services\Adm;

/**
 * PURE. Rewrites DayZ internal entity class names (infected / animals) that leak into ADM death
 * lines into human-friendly text, so the raw class token never reaches the eulogy LLM (which would
 * otherwise echo "ZmbM_JoggerSkinny_Red" verbatim). Real player gamertags and weapon names never
 * match these class-name patterns, so PvP names pass through untouched.
 */
class DayzNameHumanizer
{
    /** Known animal species classes (prefix-matched, so color/sex variants resolve too). */
    private const ANIMALS = [
        'UrsusArctos' => 'a bear',
        'CanisLupus' => 'a wolf',
        'SusScrofa' => 'a wild boar',
        'CapreolusCapreolus' => 'a roe deer',
        'CervusElaphus' => 'a red deer',
        'CervusCanadensis' => 'an elk',
        'RangiferTarandus' => 'a reindeer',
        'BosTaurus' => 'a cow',
        'OvisAries' => 'a sheep',
        'CapraHircus' => 'a goat',
        'GallusGallusDomesticus' => 'a chicken',
    ];

    /** Rewrite every infected/animal class token found anywhere in the text. */
    public static function text(string $text): string
    {
        // Infected: Zmb[M|F]_<Role><Variant…> -> "an infected <role>".
        $text = preg_replace_callback(
            '/\bZmb[MF]_(\w+)/u',
            fn ($m) => self::infected($m[1]),
            $text
        );

        // Animals: Animal_<Species>[_variant] -> friendly noun.
        return preg_replace_callback(
            '/\bAnimal_(\w+)/u',
            fn ($m) => self::animal($m[1]),
            $text
        );
    }

    /** Null-safe single-token convenience (a normal gamertag passes through unchanged). */
    public static function token(?string $name): ?string
    {
        return $name === null ? null : self::text($name);
    }

    /** The role is the leading CamelCase word: "JoggerSkinny_Red" -> "jogger". */
    private static function infected(string $suffix): string
    {
        if (preg_match('/^([A-Z][a-z]+)/', $suffix, $m)) {
            return 'an infected '.strtolower($m[1]);
        }

        return 'an infected';
    }

    private static function animal(string $suffix): string
    {
        foreach (self::ANIMALS as $species => $friendly) {
            if (str_starts_with($suffix, $species)) {
                return $friendly;
            }
        }

        return 'a wild animal';
    }
}
