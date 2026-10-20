<?php

namespace Securetxt;

/**
 * Touches et raccourcis.
 *
 * Une touche est une chaîne :
 *   "a", "É", " "                 caractère tapé
 *   "UP", "ENTER", "F2", ...      touche spéciale
 *   "C-S", "C-S-LEFT", "A-Z"      avec modificateurs C (Ctrl), S (Maj), A (Alt), dans cet ordre
 *   "MOUSE:<type>:<x>:<y>:<mods>" événement souris (press, drag, release, wheelup, wheeldown, rpress)
 *   "PASTE:<texte>"               texte collé d'un coup
 *   "RESIZE"                      fenêtre redimensionnée
 */
final class Keys
{
    public const CONTEXTS = [
        'global' => 'Général',
        'tree'   => 'Arborescence',
        'editor' => 'Éditeur',
        'list'   => 'Recherche et rétroliens',
    ];

    /** id => [contexte, libellé, touches par défaut] */
    public const ACTIONS = [
        'app.quit'     => ['global', 'Quitter', ['C-Q']],
        'app.settings' => ['global', 'Paramètres', ['C-P']],
        'app.lock'     => ['global', 'Verrouiller le coffre', ['C-L']],
        'app.search'   => ['global', 'Recherche dans tout le coffre', ['C-F']],

        'tree.open'      => ['tree', 'Ouvrir la note / plier le dossier', ['ENTER']],
        'tree.expand'    => ['tree', 'Déplier le dossier / afficher les enfants', ['RIGHT']],
        'tree.collapse'  => ['tree', 'Plier / cacher les enfants / remonter', ['LEFT']],
        'tree.toggle'    => ['tree', 'Afficher ou cacher les enfants (ou le dossier)', [' ']],
        'tree.newNote'   => ['tree', 'Nouvelle note', ['N', 'n']],
        'tree.newFolder' => ['tree', 'Nouveau dossier', ['D', 'd']],
        'tree.rename'    => ['tree', 'Renommer', ['R', 'r', 'F2']],
        'tree.delete'    => ['tree', 'Effacer', ['DEL']],
        'tree.import'    => ['tree', 'Importer des fichiers', ['I', 'i']],
        'tree.export'    => ['tree', 'Exporter la sélection', ['e']],
        'tree.exportAll' => ['tree', 'Exporter tout le coffre', ['E']],
        'tree.search'    => ['tree', 'Recherche dans tout le coffre', ['/']],
        'tree.settings'  => ['tree', 'Paramètres', ['P', 'p']],
        'tree.lock'      => ['tree', 'Verrouiller', ['L', 'l']],
        'tree.quit'      => ['tree', 'Quitter', ['Q', 'q']],

        'ed.left'         => ['editor', 'Caractère à gauche', ['LEFT']],
        'ed.right'        => ['editor', 'Caractère à droite', ['RIGHT']],
        'ed.up'           => ['editor', 'Ligne affichée au-dessus', ['UP']],
        'ed.down'         => ['editor', 'Ligne affichée en dessous', ['DOWN']],
        'ed.lineUp'       => ['editor', 'Ligne précédente (ligne réelle)', ['C-UP']],
        'ed.lineDown'     => ['editor', 'Ligne suivante (ligne réelle)', ['C-DOWN']],
        'ed.wordLeft'     => ['editor', 'Mot précédent', ['C-LEFT']],
        'ed.wordRight'    => ['editor', 'Mot suivant', ['C-RIGHT']],
        'ed.home'         => ['editor', 'Début de ligne', ['HOME']],
        'ed.end'          => ['editor', 'Fin de ligne', ['END']],
        'ed.pgup'         => ['editor', 'Page précédente', ['PGUP']],
        'ed.pgdn'         => ['editor', 'Page suivante', ['PGDN']],
        'ed.docStart'     => ['editor', 'Début de la note', ['C-HOME']],
        'ed.docEnd'       => ['editor', 'Fin de la note', ['C-END']],
        'ed.selLeft'      => ['editor', 'Sélection : caractère à gauche', ['S-LEFT']],
        'ed.selRight'     => ['editor', 'Sélection : caractère à droite', ['S-RIGHT']],
        'ed.selUp'        => ['editor', 'Sélection : ligne au-dessus', ['S-UP']],
        'ed.selDown'      => ['editor', 'Sélection : ligne en dessous', ['S-DOWN']],
        'ed.selWordLeft'  => ['editor', 'Sélection : mot précédent', ['C-S-LEFT']],
        'ed.selWordRight' => ['editor', 'Sélection : mot suivant', ['C-S-RIGHT']],
        'ed.selLineUp'    => ['editor', 'Sélection : lignes entières vers le haut', ['C-S-UP']],
        'ed.selLineDown'  => ['editor', 'Sélection : lignes entières vers le bas', ['C-S-DOWN']],
        'ed.selHome'      => ['editor', 'Sélection : jusqu\'au début de ligne', ['S-HOME']],
        'ed.selEnd'       => ['editor', 'Sélection : jusqu\'à la fin de ligne', ['S-END']],
        'ed.selDocStart'  => ['editor', 'Sélection : jusqu\'au début de la note', ['C-S-HOME']],
        'ed.selDocEnd'    => ['editor', 'Sélection : jusqu\'à la fin de la note', ['C-S-END']],
        'ed.selAll'       => ['editor', 'Tout sélectionner', ['C-A']],
        'ed.newline'      => ['editor', 'Nouvelle ligne', ['ENTER']],
        'ed.newlineAbove' => ['editor', 'Sauter une ligne au début de la ligne', ['S-ENTER']],
        'ed.newlineBelow' => ['editor', 'Nouvelle ligne en dessous', ['C-ENTER']],
        'ed.backspace'    => ['editor', 'Effacer à gauche', ['BACKSPACE']],
        'ed.delete'       => ['editor', 'Effacer à droite', ['DEL']],
        'ed.delWordLeft'  => ['editor', 'Effacer le mot à gauche', ['C-S-BACKSPACE', 'C-BACKSPACE']],
        'ed.delWordRight' => ['editor', 'Effacer le mot à droite', ['C-S-DEL', 'C-DEL']],
        'ed.delLine'      => ['editor', 'Supprimer la ligne', ['C-K']],
        'ed.indent'       => ['editor', 'Indenter', ['TAB']],
        'ed.unindent'     => ['editor', 'Désindenter', ['S-TAB']],
        'ed.copy'         => ['editor', 'Copier', ['C-C']],
        'ed.cut'          => ['editor', 'Couper', ['C-X']],
        'ed.paste'        => ['editor', 'Coller', ['C-V']],
        'ed.undo'         => ['editor', 'Annuler', ['C-Z']],
        'ed.redo'         => ['editor', 'Rétablir', ['C-Y', 'C-S-Z']],
        'ed.save'         => ['editor', 'Enregistrer', ['C-S']],
        'ed.followLink'   => ['editor', 'Ouvrir le lien [[...]] sous le curseur', ['C-O']],
        'ed.backlinks'    => ['editor', 'Rétroliens (notes qui pointent ici)', ['C-B']],
        'ed.toggleWrap'   => ['editor', 'Retour à la ligne auto (oui/non)', ['A-Z']],
        'ed.close'        => ['editor', 'Fermer / revenir à la note parente', ['ESC']],

        'list.open' => ['list', 'Ouvrir le résultat', ['ENTER']],
        'list.back' => ['list', 'Retour', ['ESC']],
    ];

    private const NAMES = [
        'UP' => '↑', 'DOWN' => '↓', 'LEFT' => '←', 'RIGHT' => '→',
        'PGUP' => 'PgUp', 'PGDN' => 'PgDn', 'HOME' => 'Début', 'END' => 'Fin',
        'DEL' => 'Suppr', 'INS' => 'Inser', 'BACKSPACE' => 'Retour arr.', 'ENTER' => 'Entrée',
        'ESC' => 'Échap', 'TAB' => 'Tab', 'SPACE' => 'Espace',
    ];

    /** @var array<string,list<string>> */
    private array $bind = [];
    /** @var array<string,array<string,string>> */
    private array $lookup = [];

    public function __construct(array $overrides)
    {
        $this->load($overrides);
    }

    public function load(array $overrides): void
    {
        $this->bind = [];
        foreach (self::ACTIONS as $id => $a) {
            $keys = $overrides[$id] ?? $a[2];
            $this->bind[$id] = is_array($keys)
                ? array_values(array_unique(array_filter($keys, fn ($k) => is_string($k) && $k !== '')))
                : $a[2];
        }
        $this->index();
    }

    private function index(): void
    {
        $this->lookup = [];
        foreach ($this->bind as $id => $keys) {
            $ctx = self::ACTIONS[$id][0];
            foreach ($keys as $k) {
                $this->lookup[$ctx][$k] ??= $id;
            }
        }
    }

    public function action(string $ctx, string $key): ?string
    {
        return $this->lookup[$ctx][$key] ?? null;
    }

    /** @return list<string> */
    public function keys(string $id): array
    {
        return $this->bind[$id] ?? [];
    }

    /** Remplace les touches d'une action ; retire ces touches des actions en conflit. */
    public function set(string $id, array $keys): string
    {
        $ctx = self::ACTIONS[$id][0];
        $msgs = [];
        foreach ($keys as $k) {
            foreach ($this->bind as $other => $list) {
                if ($other === $id) {
                    continue;
                }
                $octx = self::ACTIONS[$other][0];
                if (($ctx === 'global' || $octx === 'global' || $octx === $ctx) && in_array($k, $list, true)) {
                    $this->bind[$other] = array_values(array_diff($list, [$k]));
                    $msgs[] = self::label($k) . ' retiré de « ' . self::ACTIONS[$other][1] . ' »';
                }
            }
        }
        $this->bind[$id] = array_values(array_unique($keys));
        $this->index();
        return implode(' ; ', $msgs);
    }

    public function reset(string $id): string
    {
        return $this->set($id, self::ACTIONS[$id][2]);
    }

    /** Uniquement ce qui diffère des valeurs par défaut (pour settings.json). */
    public function overrides(): array
    {
        $out = [];
        foreach ($this->bind as $id => $keys) {
            if ($keys !== self::ACTIONS[$id][2]) {
                $out[$id] = $keys;
            }
        }
        return $out;
    }

    public function display(string $id, bool $short = false): string
    {
        $k = $this->bind[$id][0] ?? null;
        return $k === null ? '' : self::label($k, $short);
    }

    public function displayAll(string $id): string
    {
        $keys = $this->bind[$id] ?? [];
        return $keys ? implode('  /  ', array_map([self::class, 'label'], $keys)) : '(aucune touche)';
    }

    /** @return array{0:list<string>,1:string} [modificateurs, touche] */
    public static function split(string $key): array
    {
        $mods = [];
        while (strlen($key) > 2 && $key[1] === '-' && strpos('CSA', $key[0]) !== false) {
            $mods[] = $key[0];
            $key = substr($key, 2);
        }
        return [$mods, $key];
    }

    public static function label(string $key, bool $short = false): string
    {
        [$mods, $base] = self::split($key);
        $name = self::NAMES[$base] ?? ($base === ' ' ? 'Espace' : $base);
        if ($short && $mods === ['C'] && strlen($base) === 1) {
            return '^' . $base;
        }
        $out = '';
        foreach ($mods as $m) {
            $out .= ['C' => 'Ctrl', 'S' => 'Maj', 'A' => 'Alt'][$m] . '+';
        }
        return $out . $name;
    }

    public static function compose(bool $c, bool $s, bool $a, string $base): string
    {
        return ($c ? 'C-' : '') . ($s ? 'S-' : '') . ($a ? 'A-' : '') . $base;
    }

    // ================================================================
    //  Décodage des séquences de terminal (Linux, macOS)
    // ================================================================

    private static function u8len(int $o): int
    {
        return $o < 0x80 ? 1 : ($o < 0xE0 ? 2 : ($o < 0xF0 ? 3 : 4));
    }

    /** @return list<string> */
    public static function parseVt(string $s): array
    {
        $out = [];
        $i = 0;
        $n = strlen($s);
        while ($i < $n) {
            $o = ord($s[$i]);
            if ($o === 27) {
                $nx = $s[$i + 1] ?? '';
                if ($nx === '[') {
                    $j = $i + 2;
                    while ($j < $n && ($b = ord($s[$j])) >= 0x20 && $b <= 0x3F) {
                        $j++;
                    }
                    if ($j >= $n) {
                        $out[] = 'ESC';
                        $i++;
                        continue;
                    }
                    $params = substr($s, $i + 2, $j - $i - 2);
                    $final = $s[$j];
                    if ($params === '200' && $final === '~') {
                        $end = strpos($s, "\e[201~", $j + 1);
                        $text = $end === false ? substr($s, $j + 1) : substr($s, $j + 1, $end - $j - 1);
                        $out[] = 'PASTE:' . str_replace(["\r\n", "\r"], "\n", $text);
                        $i = $end === false ? $n : $end + 6;
                        continue;
                    }
                    $k = self::csi($params, $final);
                    if ($k !== null) {
                        $out[] = $k;
                    }
                    $i = $j + 1;
                    continue;
                }
                if ($nx === 'O' && $i + 2 < $n) {
                    $k = ['P' => 'F1', 'Q' => 'F2', 'R' => 'F3', 'S' => 'F4', 'A' => 'UP', 'B' => 'DOWN',
                          'C' => 'RIGHT', 'D' => 'LEFT', 'H' => 'HOME', 'F' => 'END', 'M' => 'ENTER'][$s[$i + 2]] ?? null;
                    if ($k !== null) {
                        $out[] = $k;
                    }
                    $i += 3;
                    continue;
                }
                if ($nx === '' || $nx === "\e") {
                    $out[] = 'ESC';
                    $i++;
                    continue;
                }
                // Échap + touche = Alt + touche
                $len = self::u8len(ord($nx));
                $sub = self::parseVt(substr($s, $i + 1, $len));
                if (($k = $sub[0] ?? null) !== null) {
                    if (mb_strlen($k) === 1) {
                        $out[] = 'A-' . (ctype_lower($k) ? strtoupper($k) : $k);
                    } else {
                        [$m, $base] = self::split($k);
                        $out[] = self::compose(in_array('C', $m, true), in_array('S', $m, true), true, $base);
                    }
                }
                $i += 1 + $len;
                continue;
            }
            if ($o === 13 || $o === 10) { $out[] = 'ENTER'; $i++; continue; }
            if ($o === 9) { $out[] = 'TAB'; $i++; continue; }
            if ($o === 127) { $out[] = 'BACKSPACE'; $i++; continue; }
            if ($o === 8) { $out[] = 'C-BACKSPACE'; $i++; continue; }
            if ($o < 32) {
                if ($o >= 1 && $o <= 26) {
                    $out[] = 'C-' . chr(64 + $o);
                }
                $i++;
                continue;
            }
            $len = self::u8len($o);
            $ch = substr($s, $i, $len);
            $i += $len;
            if (mb_check_encoding($ch, 'UTF-8')) {
                $out[] = $ch;
            }
        }
        return $out;
    }

    private static function csi(string $params, string $final): ?string
    {
        if ($params !== '' && $params[0] === '<') {
            return self::sgrMouse(substr($params, 1), $final === 'M');
        }
        if ($params !== '' && strpos('>?=', $params[0]) !== false) {
            return null;
        }
        $p = explode(';', $params);
        $first = (int) explode(':', $p[0])[0];
        $mod = isset($p[1]) ? max(1, (int) explode(':', $p[1])[0]) : 1;
        $m = $mod - 1;
        $c = ($m & 4) !== 0;
        $sh = ($m & 1) !== 0;
        $a = ($m & 2) !== 0;
        $name = null;
        switch ($final) {
            case 'A': $name = 'UP'; break;
            case 'B': $name = 'DOWN'; break;
            case 'C': $name = 'RIGHT'; break;
            case 'D': $name = 'LEFT'; break;
            case 'H': $name = 'HOME'; break;
            case 'F': $name = 'END'; break;
            case 'P': $name = 'F1'; break;
            case 'Q': $name = 'F2'; break;
            case 'R': $name = 'F3'; break;
            case 'S': $name = 'F4'; break;
            case 'Z': return 'S-TAB';
            case 'u': return self::codeKey($first, $mod);
            case '~':
                if ($first === 27 && isset($p[2])) {
                    return self::codeKey((int) $p[2], $mod);
                }
                $name = [1 => 'HOME', 2 => 'INS', 3 => 'DEL', 4 => 'END', 5 => 'PGUP', 6 => 'PGDN', 7 => 'HOME',
                    8 => 'END', 11 => 'F1', 12 => 'F2', 13 => 'F3', 14 => 'F4', 15 => 'F5', 17 => 'F6',
                    18 => 'F7', 19 => 'F8', 20 => 'F9', 21 => 'F10', 23 => 'F11', 24 => 'F12'][$first] ?? null;
                break;
        }
        return $name === null ? null : self::compose($c, $sh, $a, $name);
    }

    /** Touche décrite par son code Unicode (protocole kitty "CSI u" et xterm modifyOtherKeys). */
    private static function codeKey(int $code, int $mod): ?string
    {
        $m = $mod - 1;
        $c = ($m & 4) !== 0;
        $s = ($m & 1) !== 0;
        $a = ($m & 2) !== 0;
        if ($code >= 57344 && $code <= 63743) {
            return null;
        }
        $named = [13 => 'ENTER', 9 => 'TAB', 27 => 'ESC', 127 => 'BACKSPACE', 8 => 'BACKSPACE', 32 => 'SPACE'];
        if (isset($named[$code])) {
            if ($code === 32 && !$c && !$a) {
                return ' ';
            }
            return self::compose($c, $s, $a, $named[$code]);
        }
        if ($code < 32) {
            return null;
        }
        $ch = mb_chr($code, 'UTF-8');
        if ($ch === false) {
            return null;
        }
        if (!$c && !$a) {
            return $s ? mb_strtoupper($ch) : $ch;
        }
        return self::compose($c, $s, $a, mb_strtoupper($ch));
    }

    private static function sgrMouse(string $params, bool $press): ?string
    {
        $p = explode(';', $params);
        if (count($p) < 3) {
            return null;
        }
        [$b, $x, $y] = array_map('intval', $p);
        $mods = (($b & 16) ? 'C' : '') . (($b & 4) ? 'S' : '') . (($b & 8) ? 'A' : '');
        if ($b & 64) {
            if (($b & 3) > 1 || !$press) {
                return null;
            }
            $kind = ($b & 3) === 0 ? 'wheelup' : 'wheeldown';
        } elseif ($b & 32) {
            if (($b & 3) !== 0) {
                return null;
            }
            $kind = 'drag';
        } else {
            $btn = $b & 3;
            if ($btn === 0) {
                $kind = $press ? 'press' : 'release';
            } elseif ($btn === 2 && $press) {
                $kind = 'rpress';
            } else {
                return null;
            }
        }
        return 'MOUSE:' . $kind . ':' . ($x - 1) . ':' . ($y - 1) . ':' . $mods;
    }
}
