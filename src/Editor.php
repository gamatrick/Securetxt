<?php

namespace Securetxt;

/**
 * Éditeur de note.
 * Les numéros de ligne sont uniquement visuels : la sélection et le presse-papiers
 * ne travaillent que sur le texte.
 */
final class EditorView implements View
{
    private const GROUPED = ['type', 'bs', 'del'];

    public string $id;
    public int $cy = 0;
    public int $cx = 0;
    /** ancre de la sélection (null = pas de sélection) */
    private ?int $ay = null;
    private int $ax = 0;
    private string $path;
    /** @var list<string> */
    private array $lines;
    private int $wantCol = 0;
    private int $topL = 0;
    private int $topK = 0;
    private int $sx = 0;
    private bool $follow = true;
    private bool $dirty = false;
    private array $undo = [];
    private array $redo = [];
    private string $lastKind = '';
    private float $lastTime = 0.0;
    /** @var array<int,?array{0:int,1:int,2:int,3:bool}> ligne d'écran => [ligne, début, fin, dernier morceau] */
    private array $rowMap = [];
    private int $bodyH = 20;
    private int $tw = 70;
    private int $gw = 5;
    private bool $dragging = false;
    private array $segCache = [];
    private array $spanCache = [];

    public function __construct(private App $app, string $id, int $line = 0, int $col = 0)
    {
        $this->id = $id;
        $this->path = (string) $app->vault->path($id);
        $this->lines = explode("\n", $this->clean($app->vault->read($id)));
        $this->bodyH = max(1, $app->h - 2);
        $this->metrics($app->w);
        $this->moveTo($line, $col);
    }

    public function rawKeys(): bool { return false; }

    public function folder(): string
    {
        return Vault::parent($this->path);
    }

    private function clean(string $t): string
    {
        $tab = str_repeat(' ', $this->app->settings->v['tabSize']);
        return str_replace(["\r\n", "\r", "\t"], ["\n", "\n", $tab], $t);
    }

    private function wrapOn(): bool
    {
        return (bool) $this->app->settings->v['wrap'];
    }

    private function metrics(int $w): void
    {
        $this->gw = $this->app->settings->v['lineNumbers'] ? max(3, strlen((string) count($this->lines))) + 2 : 1;
        $this->tw = max(1, $w - $this->gw);
    }

    public function moveTo(int $line, int $col): void
    {
        $this->cy = max(0, min($line, count($this->lines) - 1));
        $this->cx = max(0, min($col, $this->len($this->cy)));
        $this->ay = null;
        $this->follow = true;
        $this->updateWant();
    }

    public function save(): void
    {
        if ($this->dirty) {
            $this->app->vault->write($this->id, implode("\n", $this->lines));
            $this->dirty = false;
        }
    }

    private function len(int $l): int
    {
        return mb_strlen($this->lines[$l]);
    }

    // ================================================================
    //  Découpage en lignes affichées (retour à la ligne automatique)
    // ================================================================

    /** @return list<array{0:int,1:int}> morceaux [début, fin) de la ligne $l */
    private function segs(int $l): array
    {
        $line = $this->lines[$l];
        if (!$this->wrapOn()) {
            return [[0, mb_strlen($line)]];
        }
        $c = $this->segCache[$l] ?? null;
        if ($c !== null && $c[0] === $line && $c[1] === $this->tw) {
            return $c[2];
        }
        $len = mb_strlen($line);
        $tw = $this->tw;
        $segs = [];
        if ($len <= $tw) {
            $segs[] = [0, $len];
        } else {
            $chars = mb_str_split($line);
            $s = 0;
            while ($len - $s > $tw) {
                $e = $s + $tw;
                $b = $e;
                while ($b > $s && $chars[$b - 1] !== ' ') {
                    $b--;
                }
                if ($b === $s) {
                    $b = $e;
                }
                $segs[] = [$s, $b];
                $s = $b;
            }
            $segs[] = [$s, $len];
        }
        $this->segCache[$l] = [$line, $tw, $segs];
        return $segs;
    }

    private function segIndex(int $l, int $x): int
    {
        $s = $this->segs($l);
        $last = count($s) - 1;
        for ($k = 0; $k < $last; $k++) {
            if ($x < $s[$k][1]) {
                return $k;
            }
        }
        return $last;
    }

    private function updateWant(): void
    {
        $k = $this->segIndex($this->cy, $this->cx);
        $this->wantCol = $this->cx - $this->segs($this->cy)[$k][0];
    }

    private function colInSeg(int $l, int $k, int $v): int
    {
        $s = $this->segs($l);
        $seg = $s[$k];
        $max = $k === count($s) - 1 ? $seg[1] : max($seg[0], $seg[1] - 1);
        return min($seg[0] + $v, $max);
    }

    private function next(int $l, int $k): ?array
    {
        if ($k + 1 < count($this->segs($l))) {
            return [$l, $k + 1];
        }
        return $l + 1 < count($this->lines) ? [$l + 1, 0] : null;
    }

    private function prev(int $l, int $k): ?array
    {
        if ($k > 0) {
            return [$l, $k - 1];
        }
        return $l > 0 ? [$l - 1, count($this->segs($l - 1)) - 1] : null;
    }

    private function ensureVisible(): void
    {
        $k = $this->segIndex($this->cy, $this->cx);
        if ($this->wrapOn()) {
            $this->sx = 0;
        } else {
            if ($this->cx < $this->sx) {
                $this->sx = $this->cx;
            }
            if ($this->cx >= $this->sx + $this->tw) {
                $this->sx = $this->cx - $this->tw + 1;
            }
        }
        if ($this->cy < $this->topL || ($this->cy === $this->topL && $k < $this->topK)) {
            $this->topL = $this->cy;
            $this->topK = $k;
            return;
        }
        $l = $this->topL;
        $kk = $this->topK;
        $r = 0;
        while (!($l === $this->cy && $kk === $k)) {
            $p = $this->next($l, $kk);
            if ($p === null || ++$r >= $this->bodyH) {
                break;
            }
            [$l, $kk] = $p;
        }
        if ($r >= $this->bodyH) {
            $l = $this->cy;
            $kk = $k;
            for ($i = 0; $i < $this->bodyH - 1; $i++) {
                $p = $this->prev($l, $kk);
                if ($p === null) {
                    break;
                }
                [$l, $kk] = $p;
            }
            $this->topL = $l;
            $this->topK = $kk;
        }
    }

    private function scrollRows(int $n): void
    {
        for ($i = 0; $i < abs($n); $i++) {
            $p = $n < 0 ? $this->prev($this->topL, $this->topK) : $this->next($this->topL, $this->topK);
            if ($p === null) {
                break;
            }
            [$this->topL, $this->topK] = $p;
        }
        $this->follow = false;
    }

    // ================================================================
    //  Déplacements
    // ================================================================

    private function moveVisual(int $n): void
    {
        $l = $this->cy;
        $k = $this->segIndex($l, $this->cx);
        for ($i = 0; $i < abs($n); $i++) {
            $p = $n < 0 ? $this->prev($l, $k) : $this->next($l, $k);
            if ($p === null) {
                if ($i === 0) {
                    $this->cx = $n < 0 ? 0 : $this->len($l);
                    $this->updateWant();
                    return;
                }
                break;
            }
            [$l, $k] = $p;
        }
        $this->cy = $l;
        $this->cx = $this->colInSeg($l, $k, $this->wantCol);
    }

    private function moveLine(int $n): void
    {
        $this->cy = max(0, min(count($this->lines) - 1, $this->cy + $n));
        $this->cx = min($this->cx, $this->len($this->cy));
        $this->updateWant();
    }

    private function left(): void
    {
        if ($this->cx > 0) {
            $this->cx--;
        } elseif ($this->cy > 0) {
            $this->cy--;
            $this->cx = $this->len($this->cy);
        }
        $this->updateWant();
    }

    private function right(): void
    {
        if ($this->cx < $this->len($this->cy)) {
            $this->cx++;
        } elseif ($this->cy < count($this->lines) - 1) {
            $this->cy++;
            $this->cx = 0;
        }
        $this->updateWant();
    }

    private function home(): void
    {
        $line = $this->lines[$this->cy];
        $indent = strlen($line) - strlen(ltrim($line, ' '));
        $this->cx = $this->cx === $indent ? 0 : $indent;
        $this->updateWant();
    }

    private static function cls(string $ch): int
    {
        if ($ch === ' ') {
            return 0;
        }
        return preg_match('/^[\p{L}\p{N}_]$/u', $ch) ? 1 : 2;
    }

    /** Position du mot précédent (-1) ou suivant (+1). @return array{0:int,1:int} */
    private function wordPos(int $dir): array
    {
        $l = $this->cy;
        $x = $this->cx;
        if ($dir < 0) {
            if ($x === 0) {
                return $l > 0 ? [$l - 1, $this->len($l - 1)] : [0, 0];
            }
            $chars = mb_str_split(mb_substr($this->lines[$l], 0, $x));
            $i = $x;
            while ($i > 0 && $chars[$i - 1] === ' ') {
                $i--;
            }
            if ($i > 0) {
                $c = self::cls($chars[$i - 1]);
                while ($i > 0 && self::cls($chars[$i - 1]) === $c) {
                    $i--;
                }
            }
            return [$l, $i];
        }
        $len = $this->len($l);
        if ($x >= $len) {
            return $l < count($this->lines) - 1 ? [$l + 1, 0] : [$l, $len];
        }
        $chars = mb_str_split(mb_substr($this->lines[$l], $x));
        $n = count($chars);
        $i = 0;
        while ($i < $n && $chars[$i] === ' ') {
            $i++;
        }
        if ($i < $n) {
            $c = self::cls($chars[$i]);
            while ($i < $n && self::cls($chars[$i]) === $c) {
                $i++;
            }
        }
        return [$l, $x + $i];
    }

    // ================================================================
    //  Sélection
    // ================================================================

    private function extend(): void
    {
        if ($this->ay === null) {
            $this->ay = $this->cy;
            $this->ax = $this->cx;
        }
    }

    private function hasSel(): bool
    {
        return $this->ay !== null && ($this->ay !== $this->cy || $this->ax !== $this->cx);
    }

    /** @return array{0:array{0:int,1:int},1:array{0:int,1:int}} début, fin */
    private function bounds(): array
    {
        $a = [$this->ay, $this->ax];
        $b = [$this->cy, $this->cx];
        return ($a[0] < $b[0] || ($a[0] === $b[0] && $a[1] <= $b[1])) ? [$a, $b] : [$b, $a];
    }

    private function textBetween(array $a, array $b): string
    {
        if ($a[0] === $b[0]) {
            return mb_substr($this->lines[$a[0]], $a[1], $b[1] - $a[1]);
        }
        $out = mb_substr($this->lines[$a[0]], $a[1]);
        for ($l = $a[0] + 1; $l < $b[0]; $l++) {
            $out .= "\n" . $this->lines[$l];
        }
        return $out . "\n" . mb_substr($this->lines[$b[0]], 0, $b[1]);
    }

    private function selText(): string
    {
        [$a, $b] = $this->bounds();
        return $this->textBetween($a, $b);
    }

    private function selectWord(int $l, int $x): void
    {
        $len = $this->len($l);
        if ($len === 0) {
            $this->cy = $l;
            $this->cx = 0;
            $this->ay = null;
            return;
        }
        $chars = mb_str_split($this->lines[$l]);
        $x = min($x, $len - 1);
        $c = self::cls($chars[$x]);
        $s = $x;
        while ($s > 0 && self::cls($chars[$s - 1]) === $c) {
            $s--;
        }
        $e = $x + 1;
        while ($e < $len && self::cls($chars[$e]) === $c) {
            $e++;
        }
        $this->ay = $l;
        $this->ax = $s;
        $this->cy = $l;
        $this->cx = $e;
    }

    private function selectLine(int $l): void
    {
        $this->ay = $l;
        $this->ax = 0;
        if ($l < count($this->lines) - 1) {
            $this->cy = $l + 1;
            $this->cx = 0;
        } else {
            $this->cy = $l;
            $this->cx = $this->len($l);
        }
    }

    // ================================================================
    //  Modifications (toutes passent par replace())
    // ================================================================

    private function snap(string $kind): void
    {
        $now = microtime(true);
        if ($kind === $this->lastKind && in_array($kind, self::GROUPED, true) && $now - $this->lastTime < 2.0) {
            $this->lastTime = $now;
            return;
        }
        $this->undo[] = [$this->lines, $this->cy, $this->cx];
        if (count($this->undo) > 300) {
            array_shift($this->undo);
        }
        $this->redo = [];
        $this->lastKind = $kind;
        $this->lastTime = $now;
    }

    /** Remplace le texte entre $a et $b par $text ; le curseur se place après. */
    private function replace(array $a, array $b, string $text): void
    {
        $left = mb_substr($this->lines[$a[0]], 0, $a[1]);
        $right = mb_substr($this->lines[$b[0]], $b[1]);
        $parts = explode("\n", $text);
        $last = count($parts) - 1;
        $endCol = mb_strlen($parts[$last]) + ($last === 0 ? mb_strlen($left) : 0);
        $parts[0] = $left . $parts[0];
        $parts[$last] .= $right;
        array_splice($this->lines, $a[0], $b[0] - $a[0] + 1, $parts);
        $this->cy = $a[0] + $last;
        $this->cx = $endCol;
        $this->ay = null;
        $this->dirty = true;
        $this->updateWant();
    }

    private function insertText(string $t, string $kind): void
    {
        $this->snap($kind);
        $cur = [$this->cy, $this->cx];
        [$a, $b] = $this->hasSel() ? $this->bounds() : [$cur, $cur];
        $this->replace($a, $b, $t);
    }

    private function deleteSel(): bool
    {
        if (!$this->hasSel()) {
            return false;
        }
        $this->snap('cut');
        [$a, $b] = $this->bounds();
        $this->replace($a, $b, '');
        return true;
    }

    /** Lignes concernées par la sélection (ou la ligne du curseur). @return array{0:int,1:int} */
    private function selLines(): array
    {
        if (!$this->hasSel()) {
            return [$this->cy, $this->cy];
        }
        [$a, $b] = $this->bounds();
        $y2 = ($b[1] === 0 && $b[0] > $a[0]) ? $b[0] - 1 : $b[0];
        return [$a[0], $y2];
    }

    private function removeLines(int $y1, int $y2): void
    {
        $this->snap('line');
        array_splice($this->lines, $y1, $y2 - $y1 + 1);
        if (!$this->lines) {
            $this->lines = [''];
        }
        $this->cy = min($y1, count($this->lines) - 1);
        $this->cx = min($this->cx, $this->len($this->cy));
        $this->ay = null;
        $this->dirty = true;
        $this->updateWant();
    }

    private function indentLines(int $dir): void
    {
        [$y1, $y2] = $this->selLines();
        $this->snap('indent');
        $tab = $this->app->settings->v['tabSize'];
        for ($l = $y1; $l <= $y2; $l++) {
            $line = $this->lines[$l];
            if ($dir > 0) {
                if ($line === '') {
                    continue;
                }
                $this->lines[$l] = str_repeat(' ', $tab) . $line;
                $n = $tab;
            } else {
                $n = min($tab, strlen($line) - strlen(ltrim($line, ' ')));
                $this->lines[$l] = substr($line, $n);
                $n = -$n;
            }
            if ($this->cy === $l) {
                $this->cx = max(0, $this->cx + $n);
            }
            if ($this->ay === $l) {
                $this->ax = max(0, $this->ax + $n);
            }
        }
        $this->dirty = true;
        $this->updateWant();
    }

    private function undoStep(bool $redo): void
    {
        $from = &$this->undo;
        $to = &$this->redo;
        if ($redo) {
            $from = &$this->redo;
            $to = &$this->undo;
        }
        if (!$from) {
            $this->app->info($redo ? 'Rien à rétablir.' : 'Rien à annuler.');
            return;
        }
        $to[] = [$this->lines, $this->cy, $this->cx];
        [$this->lines, $cy, $cx] = array_pop($from);
        $this->ay = null;
        $this->dirty = true;
        $this->lastKind = '';
        $this->cy = min($cy, count($this->lines) - 1);
        $this->cx = min($cx, $this->len($this->cy));
        $this->updateWant();
    }

    // ================================================================
    //  Clavier
    // ================================================================

    public function handle(string $k): void
    {
        $this->follow = true;
        if (strncmp($k, 'PASTE:', 6) === 0) {
            $this->insertText($this->clean(substr($k, 6)), 'paste');
            return;
        }
        if (($a = $this->app->keys->action('editor', $k)) !== null) {
            $this->perform($a);
            return;
        }
        if (mb_strlen($k) === 1) {
            $this->insertText($k, 'type');
        }
    }

    public function perform(string $a): void
    {
        $this->follow = true;
        $last = count($this->lines) - 1;
        $page = max(1, $this->bodyH - 1);
        if (!in_array($a, ['ed.backspace', 'ed.delete'], true)) {
            $this->lastKind = '';
        }
        // déplacements : sans Maj on annule la sélection, avec Maj on l'étend
        $sel = strncmp($a, 'ed.sel', 6) === 0 && $a !== 'ed.selAll' && $a !== 'ed.selLineUp' && $a !== 'ed.selLineDown';
        if ($sel) {
            $this->extend();
        }
        switch ($a) {
            case 'ed.left':
                if ($this->hasSel()) {
                    [[$this->cy, $this->cx]] = $this->bounds();
                    $this->ay = null;
                    $this->updateWant();
                    return;
                }
                $this->ay = null;
                // no break
            case 'ed.selLeft':
                $this->left();
                return;
            case 'ed.right':
                if ($this->hasSel()) {
                    [, [$this->cy, $this->cx]] = $this->bounds();
                    $this->ay = null;
                    $this->updateWant();
                    return;
                }
                $this->ay = null;
                // no break
            case 'ed.selRight':
                $this->right();
                return;
            case 'ed.up':
            case 'ed.selUp':
                $sel || $this->ay = null;
                $this->moveVisual(-1);
                return;
            case 'ed.down':
            case 'ed.selDown':
                $sel || $this->ay = null;
                $this->moveVisual(1);
                return;
            case 'ed.lineUp':
                $this->ay = null;
                $this->moveLine(-1);
                return;
            case 'ed.lineDown':
                $this->ay = null;
                $this->moveLine(1);
                return;
            case 'ed.wordLeft':
            case 'ed.selWordLeft':
                $sel || $this->ay = null;
                [$this->cy, $this->cx] = $this->wordPos(-1);
                $this->updateWant();
                return;
            case 'ed.wordRight':
            case 'ed.selWordRight':
                $sel || $this->ay = null;
                [$this->cy, $this->cx] = $this->wordPos(1);
                $this->updateWant();
                return;
            case 'ed.home':
            case 'ed.selHome':
                $sel || $this->ay = null;
                $this->home();
                return;
            case 'ed.end':
            case 'ed.selEnd':
                $sel || $this->ay = null;
                $this->cx = $this->len($this->cy);
                $this->updateWant();
                return;
            case 'ed.pgup':
                $this->ay = null;
                $this->moveVisual(-$page);
                return;
            case 'ed.pgdn':
                $this->ay = null;
                $this->moveVisual($page);
                return;
            case 'ed.docStart':
            case 'ed.selDocStart':
                $sel || $this->ay = null;
                $this->cy = $this->cx = 0;
                $this->updateWant();
                return;
            case 'ed.docEnd':
            case 'ed.selDocEnd':
                $sel || $this->ay = null;
                $this->cy = $last;
                $this->cx = $this->len($last);
                $this->updateWant();
                return;
            case 'ed.selLineDown':
                if (!$this->hasSel()) {
                    $this->ay = $this->cy;
                    $this->ax = 0;
                }
                if ($this->cy < $last) {
                    $this->cy++;
                    $this->cx = 0;
                } else {
                    $this->cx = $this->len($last);
                }
                $this->updateWant();
                return;
            case 'ed.selLineUp':
                if (!$this->hasSel()) {
                    $this->ay = $this->cy < $last ? $this->cy + 1 : $this->cy;
                    $this->ax = $this->cy < $last ? 0 : $this->len($this->cy);
                    $this->cx = 0;
                } elseif ($this->cx > 0) {
                    $this->cx = 0;
                } elseif ($this->cy > 0) {
                    $this->cy--;
                }
                $this->updateWant();
                return;
            case 'ed.selAll':
                $this->ay = 0;
                $this->ax = 0;
                $this->cy = $last;
                $this->cx = $this->len($last);
                $this->updateWant();
                return;

            case 'ed.newline':
                $indent = '';
                if ($this->app->settings->v['autoIndent']) {
                    $left = mb_substr($this->lines[$this->cy], 0, $this->cx);
                    $indent = substr($left, 0, strlen($left) - strlen(ltrim($left, ' ')));
                }
                $this->insertText("\n" . $indent, 'enter');
                return;
            case 'ed.newlineAbove':
                $this->snap('line');
                $this->ay = null;
                array_splice($this->lines, $this->cy, 0, ['']);
                $this->cy++;
                $this->dirty = true;
                return;
            case 'ed.newlineBelow':
                $this->snap('line');
                $this->ay = null;
                $line = $this->lines[$this->cy];
                $indent = $this->app->settings->v['autoIndent'] ? substr($line, 0, strlen($line) - strlen(ltrim($line, ' '))) : '';
                array_splice($this->lines, $this->cy + 1, 0, [$indent]);
                $this->cy++;
                $this->cx = strlen($indent);
                $this->dirty = true;
                $this->updateWant();
                return;
            case 'ed.backspace':
                if ($this->deleteSel()) {
                    return;
                }
                if ($this->cx > 0) {
                    $this->snap('bs');
                    $this->replace([$this->cy, $this->cx - 1], [$this->cy, $this->cx], '');
                } elseif ($this->cy > 0) {
                    $this->snap('bs');
                    $this->replace([$this->cy - 1, $this->len($this->cy - 1)], [$this->cy, 0], '');
                }
                return;
            case 'ed.delete':
                if ($this->deleteSel()) {
                    return;
                }
                if ($this->cx < $this->len($this->cy)) {
                    $this->snap('del');
                    $this->replace([$this->cy, $this->cx], [$this->cy, $this->cx + 1], '');
                } elseif ($this->cy < $last) {
                    $this->snap('del');
                    $this->replace([$this->cy, $this->cx], [$this->cy + 1, 0], '');
                }
                return;
            case 'ed.delWordLeft':
            case 'ed.delWordRight':
                if ($this->deleteSel()) {
                    return;
                }
                $cur = [$this->cy, $this->cx];
                $p = $this->wordPos($a === 'ed.delWordLeft' ? -1 : 1);
                if ($p !== $cur) {
                    $this->snap('word');
                    $a === 'ed.delWordLeft' ? $this->replace($p, $cur, '') : $this->replace($cur, $p, '');
                }
                return;
            case 'ed.delLine':
                [$y1, $y2] = $this->selLines();
                $this->removeLines($y1, $y2);
                return;
            case 'ed.indent':
                [$y1, $y2] = $this->selLines();
                if ($this->hasSel() && $y2 > $y1) {
                    $this->indentLines(1);
                } else {
                    $tab = $this->app->settings->v['tabSize'];
                    $this->insertText(str_repeat(' ', $tab - $this->cx % $tab), 'type');
                }
                return;
            case 'ed.unindent':
                $this->indentLines(-1);
                return;

            case 'ed.copy':
            case 'ed.cut':
                if ($this->hasSel()) {
                    $this->app->clip->copy($this->selText());
                    if ($a === 'ed.cut') {
                        $this->deleteSel();
                    }
                } else {
                    // sans sélection : toute la ligne
                    $this->app->clip->copy($this->lines[$this->cy] . "\n");
                    if ($a === 'ed.cut') {
                        $this->removeLines($this->cy, $this->cy);
                    }
                }
                $this->app->info($a === 'ed.cut' ? 'Coupé.' : 'Copié.');
                return;
            case 'ed.paste':
                $t = $this->app->clip->paste();
                if ($t === '') {
                    $this->app->info('Presse-papiers vide.');
                    return;
                }
                $this->insertText($this->clean($t), 'paste');
                return;
            case 'ed.undo':
                $this->undoStep(false);
                return;
            case 'ed.redo':
                $this->undoStep(true);
                return;

            case 'ed.save':
                $this->save();
                $this->app->info('Note enregistrée (chiffrée).');
                return;
            case 'ed.followLink':
                $target = $this->linkAt($this->cy, $this->cx, true);
                if ($target === null) {
                    $this->app->info('Place le curseur sur un lien [[...]] puis ' . $this->app->keys->display('ed.followLink') . '.');
                    return;
                }
                $this->app->followLink($target, $this);
                return;
            case 'ed.backlinks':
                $this->app->openBacklinks($this);
                return;
            case 'ed.toggleWrap':
                $on = !$this->wrapOn();
                $this->app->settings->set('wrap', $on);
                $this->topK = 0;
                $this->sx = 0;
                $this->app->info('Retour à la ligne automatique : ' . ($on ? 'activé' : 'désactivé'));
                return;
            case 'ed.close':
                if ($this->hasSel()) {
                    $this->ay = null;
                    return;
                }
                $this->app->closeEditor($this);
                return;
        }
    }

    // ================================================================
    //  Liens
    // ================================================================

    /** @return list<array{0:int,1:int,2:string,3:string}> [début, fin, style, cible] */
    private function spans(int $l): array
    {
        $line = $this->lines[$l];
        $ver = $this->app->vault->version();
        $c = $this->spanCache[$l] ?? null;
        if ($c !== null && $c[0] === $line && $c[1] === $ver) {
            return $c[2];
        }
        $spans = [];
        if (strpos($line, '[[') !== false && preg_match_all(Vault::LINK_RE, $line, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => [$txt, $off]) {
                $a = mb_strlen(substr($line, 0, $off));
                $ok = $this->app->resolve($m[1][$i][0], $this->folder()) !== null;
                $spans[] = [$a, $a + mb_strlen($txt), $ok ? 'link' : 'linkMissing', $m[1][$i][0]];
            }
        }
        $this->spanCache[$l] = [$line, $ver, $spans];
        return $spans;
    }

    private function linkAt(int $l, int $x, bool $inclusiveEnd): ?string
    {
        foreach ($this->spans($l) as [$a, $b, , $target]) {
            if ($x >= $a && ($x < $b || ($inclusiveEnd && $x === $b))) {
                return $target;
            }
        }
        return null;
    }

    // ================================================================
    //  Souris
    // ================================================================

    /** @return array{0:int,1:int} position dans le texte sous la case (x, y) de l'écran */
    private function posAt(int $x, int $y): array
    {
        $r = max(0, min($this->bodyH - 1, $y - 1));
        $m = $this->rowMap[$r] ?? null;
        if ($m === null) {
            $last = count($this->lines) - 1;
            return [$last, $this->len($last)];
        }
        [$l, $s, $e, $isLast] = $m;
        $col = $s + max(0, $x - $this->gw);
        return [$l, min($col, $isLast ? $e : max($s, $e - 1))];
    }

    public function mouse(string $kind, int $x, int $y, string $mods): void
    {
        if ($kind === 'wheelup' || $kind === 'wheeldown') {
            $this->scrollRows($kind === 'wheelup' ? -3 : 3);
            return;
        }
        if ($kind === 'release') {
            $this->dragging = false;
            if (!$this->hasSel()) {
                $this->ay = null;
            }
            return;
        }
        if ($kind === 'drag') {
            if (!$this->dragging) {
                return;
            }
            if ($y < 1) {
                $this->scrollRows(-1);
            } elseif ($y > $this->bodyH) {
                $this->scrollRows(1);
            }
            [$this->cy, $this->cx] = $this->posAt($x, $y);
            $this->updateWant();
            return;
        }
        if ($kind !== 'press' || $y < 1 || $y > $this->bodyH) {
            return;
        }
        $this->follow = true;
        $this->lastKind = '';
        [$l, $c] = $this->posAt($x, $y);
        if (strpos($mods, 'C') !== false && ($target = $this->linkAt($l, $c, false)) !== null) {
            $this->ay = null;
            [$this->cy, $this->cx] = [$l, $c];
            $this->app->followLink($target, $this);
            return;
        }
        $clicks = $this->app->clicks;
        if ($clicks === 2) {
            $this->selectWord($l, $c);
        } elseif ($clicks >= 3) {
            $this->selectLine($l);
        } else {
            if (strpos($mods, 'S') !== false) {
                $this->extend();
            } else {
                $this->ay = $l;
                $this->ax = $c;
            }
            [$this->cy, $this->cx] = [$l, $c];
            $this->dragging = true;
        }
        $this->updateWant();
    }

    // ================================================================
    //  Affichage
    // ================================================================

    public function render(int $w, int $h): array
    {
        $c = T::$c;
        $this->metrics($w);
        $this->bodyH = $h - 2;
        $n = count($this->lines);
        $this->topL = max(0, min($this->topL, $n - 1));
        $this->topK = max(0, min($this->topK, count($this->segs($this->topL)) - 1));
        if ($this->follow) {
            $this->ensureVisible();
        }
        $wrap = $this->wrapOn();
        $lineNumbers = $this->app->settings->v['lineNumbers'];
        $selB = $this->hasSel() ? $this->bounds() : null;

        $right = ($selB ? mb_strlen($this->selText()) . ' car. sélectionnés   ' : '')
            . 'L' . ($this->cy + 1) . ':' . ($this->cx + 1) . ($wrap ? '  ↵' : '') . ' ';
        $out = [$this->app->header(' ' . $this->path . ($this->dirty ? ' *' : ''), $right)];

        $this->rowMap = [];
        $l = $this->topL;
        $k = $this->topK;
        for ($y = 0; $y < $this->bodyH; $y++) {
            $row = new Row($w, $c['text']);
            if ($l === null) {
                $row->add(str_repeat(' ', $this->gw), $c['gutter']);
                $out[] = $row->done();
                $this->rowMap[$y] = null;
                continue;
            }
            $segs = $this->segs($l);
            [$s, $e] = $segs[$k];
            $isLast = $k === count($segs) - 1;
            $num = $lineNumbers && $k === 0 ? str_pad((string) ($l + 1), $this->gw - 1, ' ', STR_PAD_LEFT) . ' ' : str_repeat(' ', $this->gw);
            $row->add($num, $l === $this->cy ? $c['gutterCur'] : $c['gutter']);
            $len = $this->len($l);
            if ($wrap) {
                $this->renderSlice($row, $l, $s, $e, $isLast, $selB);
                $this->rowMap[$y] = [$l, $s, $e, $isLast];
            } else {
                $a = min($this->sx, $len);
                $b = min($len, $this->sx + $this->tw);
                $this->renderSlice($row, $l, $a, $b, $b === $len, $selB);
                $this->rowMap[$y] = [$l, $this->sx, $len, true];
            }
            $out[] = $row->done();
            $p = $this->next($l, $k);
            if ($p === null) {
                $l = null;
            } else {
                [$l, $k] = $p;
            }
        }
        $out[] = $this->app->footer([
            ['ed.save', 'Sauver'], ['ed.followLink', 'Lien'], ['ed.close', 'Retour'], ['app.search', 'Chercher'],
            ['ed.undo', 'Annuler'], ['ed.backlinks', 'Rétroliens'], ['ed.toggleWrap', 'Retour ligne'],
            ['app.settings', 'Réglages'],
        ]);
        return [$out, $this->cursorPos($w)];
    }

    private function cursorPos(int $w): ?array
    {
        foreach ($this->rowMap as $y => $m) {
            if ($m === null || $m[0] !== $this->cy) {
                continue;
            }
            [, $s, $e, $isLast] = $m;
            if ($this->wrapOn()) {
                if ($this->cx >= $s && ($this->cx < $e || ($isLast && $this->cx <= $e))) {
                    return [min($w - 1, $this->gw + $this->cx - $s), $y + 1];
                }
                continue;
            }
            $x = $this->gw + $this->cx - $this->sx;
            return ($x >= $this->gw && $x < $w) ? [$x, $y + 1] : null;
        }
        return null;
    }

    /** Affiche les caractères [$a, $b) de la ligne $l avec titres, liens et sélection. */
    private function renderSlice(Row $row, int $l, int $a, int $b, bool $eol, ?array $selB): void
    {
        $c = T::$c;
        $line = $this->lines[$l];
        $base = ($line !== '' && preg_match('/^\s*#/', $line)) ? $c['heading'] : null;
        $spans = $this->spans($l);
        $sel = null;
        if ($selB !== null && $l >= $selB[0][0] && $l <= $selB[1][0]) {
            $sel = [
                $l === $selB[0][0] ? $selB[0][1] : 0,
                $l === $selB[1][0] ? $selB[1][1] : PHP_INT_MAX,
                $l < $selB[1][0],
            ];
        }
        if ($b > $a) {
            if ($sel === null && !$spans) {
                $row->add(mb_substr($line, $a, $b - $a), $base);
            } else {
                $chars = mb_str_split(mb_substr($line, $a, $b - $a));
                $buf = '';
                $runStyle = false;
                for ($i = $a; $i < $b; $i++) {
                    if ($sel !== null && $i >= $sel[0] && $i < $sel[1]) {
                        $st = $c['textSel'];
                    } else {
                        $st = $base;
                        foreach ($spans as $sp) {
                            if ($i >= $sp[0] && $i < $sp[1]) {
                                $st = $c[$sp[2]];
                                break;
                            }
                        }
                    }
                    if ($st !== $runStyle && $buf !== '') {
                        $row->add($buf, $runStyle);
                        $buf = '';
                    }
                    $runStyle = $st;
                    $buf .= $chars[$i - $a];
                }
                if ($buf !== '') {
                    $row->add($buf, $runStyle);
                }
            }
        }
        if ($eol && $sel !== null && $sel[2]) {
            $row->add(' ', $c['textSel']);
        }
    }
}
