<?php

namespace Securetxt;

final class Text
{
    /** Remplacements 1 caractère → 1 caractère : les positions restent identiques. */
    private const MAP = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n',
    ];

    /** Minuscules sans accents : sert au tri et à la recherche. */
    public static function fold(string $s): string
    {
        return strtr(mb_strtolower($s, 'UTF-8'), self::MAP);
    }

    /** Ordre alphabétique "humain" : sans casse, sans accents, nombres naturels (note2 < note10). */
    public static function cmp(string $a, string $b): int
    {
        return strnatcmp(self::fold($a), self::fold($b)) ?: strcmp($a, $b);
    }
}
