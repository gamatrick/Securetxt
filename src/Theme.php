<?php

namespace Securetxt;

/**
 * Thèmes de couleurs (256 couleurs ANSI, nuancier :
 * https://en.wikipedia.org/wiki/ANSI_escape_code#8-bit).
 * Chaque élément : [texte, fond, gras, souligné].
 */
final class T
{
    public const THEMES = [
        'smile'     => 'Smile Basic',
        'nuit'      => 'Nuit',
        'ambre'     => 'Ambre (vieux terminal)',
        'phosphore' => 'Phosphore vert',
        'papier'    => 'Papier (clair)',
    ];

    public const ELEMENTS = [
        'text'        => 'Texte',
        'dim'         => 'Texte discret',
        'heading'     => 'Titres (# ...)',
        'link'        => 'Lien vers une note existante',
        'linkMissing' => 'Lien vers une note à créer',
        'textSel'     => 'Texte sélectionné',
        'folder'      => 'Dossiers',
        'folderIcon'  => 'Icône de dossier [-]',
        'kidsIcon'    => 'Symbole ► / ▼ (note avec enfants)',
        'treeChild'   => 'Notes enfants dans l\'arborescence',
        'gutter'      => 'Numéros de ligne',
        'gutterCur'   => 'Numéro de la ligne courante',
        'bar'         => 'Barre du haut',
        'status'      => 'Barre du bas',
        'statusKey'   => 'Touches de la barre du bas',
        'sel'         => 'Ligne sélectionnée (listes)',
        'match'       => 'Mot trouvé (recherche)',
        'input'       => 'Champ de saisie',
        'inputLabel'  => 'Libellé du champ de saisie',
        'flash'       => 'Message d\'information',
        'error'       => 'Message d\'erreur',
        'logo'        => 'Logo',
    ];

    private const SPECS = [
        'smile' => ['bg' => 24, 'fg' => 255, 'dim' => 110, 'strong' => 195, 'folder' => 159, 'icon' => 151,
            'link' => 120, 'warn' => 222, 'barFg' => 17, 'barBg' => 152, 'stFg' => 22, 'stBg' => 151,
            'keyFg' => 231, 'keyBg' => 65, 'selFg' => 17, 'selBg' => 152, 'matchFg' => 17, 'matchBg' => 151,
            'gutFg' => 110, 'gutBg' => 23, 'inFg' => 231, 'inBg' => 31, 'errBg' => 131],
        'nuit' => ['bg' => 234, 'fg' => 252, 'dim' => 243, 'strong' => 255, 'folder' => 117, 'icon' => 110,
            'link' => 114, 'warn' => 221, 'barFg' => 255, 'barBg' => 238, 'stFg' => 250, 'stBg' => 236,
            'keyFg' => 234, 'keyBg' => 110, 'selFg' => 255, 'selBg' => 25, 'matchFg' => 234, 'matchBg' => 221,
            'gutFg' => 240, 'gutBg' => 233, 'inFg' => 255, 'inBg' => 237, 'errBg' => 124],
        'ambre' => ['bg' => 16, 'fg' => 214, 'dim' => 136, 'strong' => 222, 'folder' => 220, 'icon' => 172,
            'link' => 229, 'warn' => 208, 'barFg' => 16, 'barBg' => 214, 'stFg' => 16, 'stBg' => 172,
            'keyFg' => 214, 'keyBg' => 52, 'selFg' => 16, 'selBg' => 214, 'matchFg' => 16, 'matchBg' => 222,
            'gutFg' => 94, 'gutBg' => 233, 'inFg' => 222, 'inBg' => 52, 'errBg' => 88],
        'phosphore' => ['bg' => 16, 'fg' => 77, 'dim' => 28, 'strong' => 120, 'folder' => 84, 'icon' => 34,
            'link' => 157, 'warn' => 226, 'barFg' => 16, 'barBg' => 40, 'stFg' => 16, 'stBg' => 28,
            'keyFg' => 120, 'keyBg' => 22, 'selFg' => 16, 'selBg' => 77, 'matchFg' => 16, 'matchBg' => 157,
            'gutFg' => 28, 'gutBg' => 233, 'inFg' => 120, 'inBg' => 22, 'errBg' => 88],
        'papier' => ['bg' => 230, 'fg' => 236, 'dim' => 245, 'strong' => 17, 'folder' => 25, 'icon' => 101,
            'link' => 28, 'warn' => 130, 'barFg' => 230, 'barBg' => 24, 'stFg' => 236, 'stBg' => 187,
            'keyFg' => 230, 'keyBg' => 28, 'selFg' => 230, 'selBg' => 31, 'matchFg' => 236, 'matchBg' => 222,
            'gutFg' => 246, 'gutBg' => 223, 'inFg' => 236, 'inBg' => 223, 'errBg' => 160],
    ];

    /** @var array<string,string> séquences ANSI prêtes à l'emploi */
    public static array $c = [];
    /** @var array<string,array{0:int,1:int,2:bool,3:bool}> valeurs actuelles */
    public static array $raw = [];

    public static function preset(string $theme): array
    {
        $s = self::SPECS[$theme] ?? self::SPECS['smile'];
        return [
            'text'        => [$s['fg'], $s['bg'], false, false],
            'dim'         => [$s['dim'], $s['bg'], false, false],
            'heading'     => [$s['strong'], $s['bg'], true, false],
            'link'        => [$s['link'], $s['bg'], true, true],
            'linkMissing' => [$s['warn'], $s['bg'], false, true],
            'textSel'     => [$s['selFg'], $s['selBg'], false, false],
            'folder'      => [$s['folder'], $s['bg'], true, false],
            'folderIcon'  => [$s['icon'], $s['bg'], false, false],
            'kidsIcon'    => [$s['link'], $s['bg'], false, false],
            'treeChild'   => [$s['link'], $s['bg'], false, false],
            'gutter'      => [$s['gutFg'], $s['gutBg'], false, false],
            'gutterCur'   => [$s['strong'], $s['gutBg'], true, false],
            'bar'         => [$s['barFg'], $s['barBg'], true, false],
            'status'      => [$s['stFg'], $s['stBg'], false, false],
            'statusKey'   => [$s['keyFg'], $s['keyBg'], true, false],
            'sel'         => [$s['selFg'], $s['selBg'], false, false],
            'match'       => [$s['matchFg'], $s['matchBg'], true, false],
            'input'       => [$s['inFg'], $s['inBg'], false, false],
            'inputLabel'  => [$s['inFg'], $s['inBg'], true, false],
            'flash'       => [$s['stFg'], $s['stBg'], true, false],
            'error'       => [231, $s['errBg'], true, false],
            'logo'        => [$s['icon'], $s['bg'], true, false],
        ];
    }

    public static function apply(string $theme, array $overrides): void
    {
        $p = self::preset($theme);
        foreach ($overrides as $k => $v) {
            if (isset($p[$k]) && self::valid($v)) {
                $p[$k] = [(int) $v[0], (int) $v[1], (bool) $v[2], (bool) $v[3]];
            }
        }
        self::$raw = $p;
        foreach ($p as $k => $v) {
            self::$c[$k] = self::sgr($v);
        }
    }

    public static function valid($v): bool
    {
        return is_array($v) && count($v) === 4 && is_int($v[0]) && is_int($v[1])
            && $v[0] >= 0 && $v[0] <= 255 && $v[1] >= 0 && $v[1] <= 255;
    }

    public static function sgr(array $v): string
    {
        return "\e[0;" . ($v[2] ? '1;' : '') . ($v[3] ? '4;' : '') . "38;5;{$v[0]};48;5;{$v[1]}m";
    }

    public static function swatch(int $color): string
    {
        return "\e[0;48;5;{$color}m";
    }
}
