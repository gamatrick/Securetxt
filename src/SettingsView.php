<?php

namespace Securetxt;

// =====================================================================
//  Paramètres : tout est modifiable ici et enregistré immédiatement
// =====================================================================
final class SettingsView implements View
{
    /** @var list<array<string,mixed>> */
    private array $rows = [];
    private int $sel = 1;
    private int $top = 0;
    private int $bodyH = 20;
    private bool $follow = true;
    private ?string $capturing = null;
    private bool $captureAdd = false;

    public function __construct(private App $app, public View $back)
    {
        $this->rows = [
            ['t' => 'section', 'label' => 'APPARENCE'],
            ['t' => 'choice', 'k' => 'theme', 'label' => 'Thème', 'opts' => null],
            ['t' => 'action', 'k' => 'colors', 'label' => 'Couleurs personnalisées…'],
            ['t' => 'section', 'label' => 'ÉDITEUR'],
            ['t' => 'bool', 'k' => 'wrap', 'label' => 'Retour à la ligne automatique'],
            ['t' => 'bool', 'k' => 'lineNumbers', 'label' => 'Numéros de ligne'],
            ['t' => 'bool', 'k' => 'autoIndent', 'label' => 'Indentation automatique'],
            ['t' => 'int', 'k' => 'tabSize', 'label' => 'Largeur d\'une tabulation', 'min' => 1, 'max' => 8, 'step' => 1, 'suffix' => ' espaces'],
            ['t' => 'section', 'label' => 'SOURIS'],
            ['t' => 'bool', 'k' => 'mouse', 'label' => 'Utiliser la souris'],
            ['t' => 'section', 'label' => 'APPLICATION'],
            ['t' => 'action', 'k' => 'shortcut', 'label' => 'Créer un raccourci (Bureau + menu Démarrer)'],
            ['t' => 'action', 'k' => 'reset', 'label' => 'Rétablir tous les réglages par défaut'],
        ];
        foreach (Keys::CONTEXTS as $ctx => $label) {
            $this->rows[] = ['t' => 'section', 'label' => 'RACCOURCIS : ' . mb_strtoupper($label)];
            foreach (Keys::ACTIONS as $id => $a) {
                if ($a[0] === $ctx) {
                    $this->rows[] = ['t' => 'bind', 'k' => $id, 'label' => $a[1]];
                }
            }
        }
    }

    public function rawKeys(): bool
    {
        return $this->capturing !== null;
    }

    public function render(int $w, int $h): array
    {
        $c = T::$c;
        $this->bodyH = $h - 2;
        if ($this->follow) {
            if ($this->sel < $this->top + 1) {
                $this->top = max(0, $this->sel - 1);
            }
            if ($this->sel >= $this->top + $this->bodyH) {
                $this->top = $this->sel - $this->bodyH + 1;
            }
        }
        $this->top = max(0, min($this->top, count($this->rows) - 1));
        $out = [$this->app->header(' PARAMÈTRES', 'enregistrés automatiquement ')];
        $maxLabel = 0;
        foreach ($this->rows as $r) {
            if ($r['t'] !== 'section') {
                $maxLabel = max($maxLabel, mb_strlen($r['label']));
            }
        }
        $labelW = max(16, min($maxLabel + 6, $w - 26));
        for ($i = 0; $i < $this->bodyH; $i++) {
            $idx = $this->top + $i;
            $r = $this->rows[$idx] ?? null;
            $row = new Row($w, $c['text']);
            if ($r === null) {
                $out[] = $row->done();
                continue;
            }
            if ($r['t'] === 'section') {
                $out[] = $row->add(' ' . $r['label'], $c['heading'])->done();
                continue;
            }
            $selected = $idx === $this->sel;
            $row = new Row($w, $selected ? $c['sel'] : $c['text']);
            $label = '   ' . $r['label'];
            if (mb_strlen($label) > $labelW - 2) {
                $label = mb_substr($label, 0, $labelW - 3) . '…';
            }
            $row->add($label)->add(str_repeat(' ', max(1, $labelW - mb_strlen($label))));
            $value = $this->value($r);
            if ($selected && in_array($r['t'], ['choice', 'bool', 'int'], true)) {
                $value = '◄ ' . $value . ' ►';
            }
            $row->add($value, $selected ? null : ($r['t'] === 'bind' ? $c['dim'] : $c['folder']));
            $out[] = $row->done();
        }
        $cur = $this->rows[$this->sel];
        if ($cur['t'] === 'bind') {
            $items = [['key:ENTER', 'Remplacer'], ['key:+', 'Ajouter'], ['key:DEL', 'Effacer'],
                ['key:BACKSPACE', 'Défaut'], ['key:ESC', 'Retour']];
        } elseif ($cur['k'] === 'theme' && $this->app->settings->customName() !== null) {
            $items = [['key:RIGHT', 'Changer'], ['key:DEL', 'Supprimer ce thème'], ['key:ESC', 'Retour']];
        } else {
            $items = [['key:ENTER', 'Modifier'], ['key:RIGHT', 'Changer'], ['key:ESC', 'Retour']];
        }
        $out[] = $this->app->footer($items);
        return [$out, null];
    }

    private function value(array $r): string
    {
        $v = $this->app->settings->v;
        switch ($r['t']) {
            case 'choice': return $this->opts($r)[$v[$r['k']]] ?? '?';
            case 'bool': return $v[$r['k']] ? 'Oui' : 'Non';
            case 'int': return $v[$r['k']] . $r['suffix'];
            case 'action': return '';
            case 'bind':
                if ($this->capturing === $r['k']) {
                    return 'Appuie sur la touche…  (Échap = annuler)';
                }
                return $this->app->keys->displayAll($r['k']);
        }
        return '';
    }

    private function opts(array $r): array
    {
        return $r['opts'] ?? $this->app->settings->themeOptions();
    }

    private function move(int $d): void
    {
        $n = count($this->rows);
        $i = $this->sel;
        do {
            $i += $d;
        } while ($i >= 0 && $i < $n && $this->rows[$i]['t'] === 'section');
        if ($i >= 0 && $i < $n) {
            $this->sel = $i;
        }
    }

    public function handle(string $k): void
    {
        if ($this->capturing !== null) {
            $this->capture($k);
            return;
        }
        $this->follow = true;
        $r = $this->rows[$this->sel];
        switch ($k) {
            case 'UP': $this->move(-1); return;
            case 'DOWN': $this->move(1); return;
            case 'PGUP': for ($i = 0; $i < $this->bodyH - 1; $i++) { $this->move(-1); } return;
            case 'PGDN': for ($i = 0; $i < $this->bodyH - 1; $i++) { $this->move(1); } return;
            case 'HOME': $this->sel = 0; $this->move(1); return;
            case 'END': $this->sel = count($this->rows); $this->move(-1); return;
            case 'LEFT': $this->change($r, -1); return;
            case 'RIGHT': $this->change($r, 1); return;
            case 'ENTER': $this->activate($r); return;
            case 'ESC': $this->app->view = $this->back; return;
        }
        if ($k === 'DEL' && ($r['k'] ?? '') === 'theme' && ($name = $this->app->settings->customName()) !== null) {
            $this->app->confirm('Supprimer le thème « ' . $name . ' » ?', function () use ($name): void {
                $this->app->settings->deleteTheme($name);
                $this->app->applyTheme();
                $this->app->info('Thème « ' . $name . ' » supprimé.');
            });
            return;
        }
        if ($r['t'] !== 'bind') {
            return;
        }
        if ($k === '+') {
            $this->capturing = $r['k'];
            $this->captureAdd = true;
        } elseif ($k === 'DEL') {
            $this->saveKeys($this->app->keys->set($r['k'], []));
            $this->app->info('Raccourci effacé.');
        } elseif ($k === 'BACKSPACE') {
            $msg = $this->app->keys->reset($r['k']);
            $this->saveKeys($msg);
            $this->app->info('Touche par défaut rétablie' . ($msg !== '' ? ' — ' . $msg : '.'));
        }
    }

    public function perform(string $action): void
    {
    }

    public function mouse(string $kind, int $x, int $y, string $mods): void
    {
        if ($this->capturing !== null) {
            return;
        }
        if ($kind === 'wheelup' || $kind === 'wheeldown') {
            $this->top = max(0, $this->top + ($kind === 'wheelup' ? -3 : 3));
            $this->follow = false;
            return;
        }
        $idx = $this->top + $y - 1;
        if ($kind !== 'press' || $y < 1 || $y > $this->bodyH || !isset($this->rows[$idx]) || $this->rows[$idx]['t'] === 'section') {
            return;
        }
        $this->sel = $idx;
        $this->follow = true;
        if ($this->app->clicks >= 2) {
            $this->activate($this->rows[$idx]);
        }
    }

    private function capture(string $k): void
    {
        if (strncmp($k, 'PASTE:', 6) === 0 || strncmp($k, 'MOUSE:', 6) === 0) {
            return;
        }
        $id = $this->capturing;
        $this->capturing = null;
        if ($k === 'ESC') {
            $this->app->info('Annulé.');
            return;
        }
        $ctx = Keys::ACTIONS[$id][0];
        if (($ctx === 'editor' || $ctx === 'global' || $ctx === 'list') && mb_strlen($k) === 1) {
            $this->app->error('Ici, choisis une combinaison avec Ctrl ou Alt, ou une touche spéciale (sinon elle serait tapée comme du texte).');
            return;
        }
        $keys = $this->captureAdd ? array_merge($this->app->keys->keys($id), [$k]) : [$k];
        $msg = $this->app->keys->set($id, $keys);
        $this->saveKeys($msg);
        $this->app->info('« ' . Keys::ACTIONS[$id][1] . ' » = ' . $this->app->keys->displayAll($id) . ($msg !== '' ? ' — ' . $msg : ''));
    }

    private function saveKeys(string $msg): void
    {
        $this->app->settings->set('keys', $this->app->keys->overrides());
    }

    private function change(array $r, int $dir): void
    {
        $s = $this->app->settings;
        switch ($r['t']) {
            case 'choice':
                $keys = array_keys($this->opts($r));
                $i = array_search($s->v[$r['k']], $keys, true);
                $new = $keys[((int) $i + $dir + count($keys)) % count($keys)];
                if ($r['k'] === 'theme') {
                    $this->setTheme($new);
                    return;
                }
                $s->set($r['k'], $new);
                break;
            case 'bool':
                $s->set($r['k'], !$s->v[$r['k']]);
                break;
            case 'int':
                $s->set($r['k'], max($r['min'], min($r['max'], $s->v[$r['k']] + $dir * $r['step'])));
                break;
            default:
                return;
        }
        $this->applied($r['k']);
    }

    private function applied(string $k): void
    {
        $v = $this->app->settings->v;
        if ($k === 'mouse') {
            $this->app->setMouse($v['mouse']);
        }
    }

    private function setTheme(string $theme): void
    {
        $do = function () use ($theme): void {
            $this->app->settings->set('colors', []);
            $this->app->settings->set('theme', $theme);
            $this->app->applyTheme();
        };
        if ($this->app->settings->v['colors']) {
            $this->app->confirm('Tes modifications de couleurs non enregistrées seront perdues. Continuer ?', $do);
        } else {
            $do();
        }
    }

    private function activate(array $r): void
    {
        switch ($r['t']) {
            case 'choice':
            case 'bool':
            case 'int':
                $this->change($r, 1);
                return;
            case 'bind':
                $this->capturing = $r['k'];
                $this->captureAdd = false;
                return;
        }
        switch ($r['k']) {
            case 'colors':
                $this->app->view = new ColorView($this->app, $this);
                return;
            case 'shortcut':
                $this->app->info(Shortcut::create($this->app->appDir));
                return;
            case 'reset':
                $this->app->confirm('Rétablir tous les réglages par défaut (tes thèmes enregistrés sont gardés) ?', function (): void {
                    $themes = $this->app->settings->v['customThemes'];
                    $this->app->settings->reset();
                    $this->app->settings->set('customThemes', $themes);
                    $this->app->keys->load([]);
                    $this->app->applyTheme();
                    $this->app->relayout();
                    $this->app->setMouse($this->app->settings->v['mouse']);
                    $this->app->info('Réglages par défaut rétablis.');
                });
                return;
        }
    }
}

// =====================================================================
//  Couleurs personnalisées, élément par élément
// =====================================================================
final class ColorView implements View
{
    private const FIELDS = ['Texte', 'Fond', 'Gras', 'Souligné'];

    /** @var list<string> */
    private array $els;
    private int $sel = 0;
    private int $top = 0;
    private int $field = 0;
    private int $bodyH = 20;
    private bool $follow = true;
    /** @var list<array{0:int,1:int}> position x des champs */
    private array $fieldX = [];

    public function __construct(private App $app, private View $back)
    {
        $this->els = array_keys(T::ELEMENTS);
    }

    public function rawKeys(): bool { return false; }
    public function perform(string $action): void {}

    public function render(int $w, int $h): array
    {
        $c = T::$c;
        $this->bodyH = max(1, $h - 6);
        if ($this->follow) {
            if ($this->sel < $this->top) {
                $this->top = $this->sel;
            }
            if ($this->sel >= $this->top + $this->bodyH) {
                $this->top = $this->sel - $this->bodyH + 1;
            }
        }
        $this->top = max(0, min($this->top, count($this->els) - 1));
        $theme = $this->app->settings->themeLabel();
        $n = count($this->app->settings->v['colors']);
        $out = [$this->app->header(' COULEURS — thème : ' . $theme, ($n ? $n . ' modif. non enregistrée(s) ' : 'enregistré '))];
        $widths = [7, 6, 6, 10];
        $labelW = max(12, min(36, $w - 13 - array_sum($widths)));
        $head = new Row($w, $c['text']);
        $head->add(str_pad(' Élément', $labelW + 1) . str_repeat(' ', 11), $c['dim']);
        foreach (self::FIELDS as $f => $name) {
            $head->add(str_pad(' ' . $name, $widths[$f] + (strlen($name) - mb_strlen($name))), $c['dim']);
        }
        $out[] = $head->done();
        $this->fieldX = [];
        for ($i = 0; $i < $this->bodyH; $i++) {
            $idx = $this->top + $i;
            $el = $this->els[$idx] ?? null;
            $row = new Row($w, $c['text']);
            if ($el === null) {
                $out[] = $row->done();
                continue;
            }
            $selected = $idx === $this->sel;
            $v = T::$raw[$el];
            $mark = isset($this->app->settings->v['colors'][$el]) ? '*' : ' ';
            $label = $mark . ' ' . T::ELEMENTS[$el];
            if (mb_strlen($label) > $labelW) {
                $label = mb_substr($label, 0, $labelW - 1) . '…';
            }
            $row->add(' ')->add($label, $selected ? $c['heading'] : null);
            $row->add(str_repeat(' ', $labelW - mb_strlen($label) + 1));
            $row->add(' Exemple ', $c[$el])->add('  ');
            $vals = [(string) $v[0], (string) $v[1], $v[2] ? 'oui' : 'non', $v[3] ? 'oui' : 'non'];
            foreach ($widths as $f => $fw) {
                $x0 = $row->used();
                $row->add(str_pad(' ' . $vals[$f], $fw - 1) . ' ', $selected && $f === $this->field ? $c['sel'] : null);
                if ($selected || !$this->fieldX) {
                    $this->fieldX[$f] = [$x0, $row->used()];
                }
            }
            $out[] = $row->done();
        }
        // nuancier autour de la valeur courante
        $out[] = (new Row($w, $c['text']))->done();
        $el = $this->els[$this->sel];
        $row = new Row($w, $c['text']);
        if ($this->field < 2) {
            $v = T::$raw[$el][$this->field];
            $row->add('  ' . self::FIELDS[$this->field] . ' : ');
            for ($d = -8; $d <= 8; $d++) {
                $col = ($v + $d + 256) % 256;
                $row->add($d === 0 ? '[ ]' : '   ', T::swatch($col));
            }
            $row->add('  n° ' . $v . '  (Maj+←→ : ±10)', $c['dim']);
        } else {
            $row->add('  ← → ou Entrée pour activer / désactiver', $c['dim']);
        }
        $out[] = $row->done();
        $out[] = (new Row($w, $c['text']))->add('  * = modifiée, pas encore enregistrée.  S = enregistrer comme thème.  R = annuler la modif.', $c['dim'])->done();
        $out[] = $this->app->footer([['key:S', 'Enregistrer comme thème'], ['key:TAB', 'Champ'], ['key:RIGHT', '±1'],
            ['key:ENTER', 'Saisir'], ['key:R', 'Annuler la modif'], ['key:ESC', 'Retour']]);
        return [$out, null];
    }

    public function handle(string $k): void
    {
        $this->follow = true;
        $n = count($this->els);
        switch ($k) {
            case 'UP': $this->sel = max(0, $this->sel - 1); return;
            case 'DOWN': $this->sel = min($n - 1, $this->sel + 1); return;
            case 'PGUP': $this->sel = max(0, $this->sel - $this->bodyH + 1); return;
            case 'PGDN': $this->sel = min($n - 1, $this->sel + $this->bodyH - 1); return;
            case 'HOME': $this->sel = 0; return;
            case 'END': $this->sel = $n - 1; return;
            case 'TAB': $this->field = ($this->field + 1) % 4; return;
            case 'S-TAB': $this->field = ($this->field + 3) % 4; return;
            case 'LEFT': $this->bump(-1); return;
            case 'RIGHT': $this->bump(1); return;
            case 'S-LEFT': $this->bump(-10); return;
            case 'S-RIGHT': $this->bump(10); return;
            case 'ENTER': $this->enter(); return;
            case 'r': case 'R': case 'BACKSPACE': $this->resetEl(); return;
            case 's': case 'S': $this->saveAs(); return;
            case 'ESC': $this->app->view = $this->back; return;
        }
    }

    public function mouse(string $kind, int $x, int $y, string $mods): void
    {
        if ($kind === 'wheelup' || $kind === 'wheeldown') {
            $this->top = max(0, $this->top + ($kind === 'wheelup' ? -3 : 3));
            $this->follow = false;
            return;
        }
        $idx = $this->top + $y - 2;
        if ($kind !== 'press' || $y < 2 || $y > $this->bodyH + 1 || !isset($this->els[$idx])) {
            return;
        }
        $this->sel = $idx;
        $this->follow = true;
        foreach ($this->fieldX as $f => [$x0, $x1]) {
            if ($x >= $x0 && $x < $x1) {
                $this->field = $f;
                if ($this->app->clicks >= 2) {
                    $this->enter();
                }
            }
        }
    }

    private function setEl(string $el, array $v): void
    {
        $s = $this->app->settings;
        $colors = $s->v['colors'];
        $colors[$el] = $v;
        $s->set('colors', $colors);
        $this->app->applyTheme();
    }

    private function bump(int $d): void
    {
        $el = $this->els[$this->sel];
        $v = T::$raw[$el];
        if ($this->field < 2) {
            $v[$this->field] = ($v[$this->field] + $d + 256) % 256;
        } else {
            $v[$this->field] = !$v[$this->field];
        }
        $this->setEl($el, $v);
    }

    private function enter(): void
    {
        if ($this->field >= 2) {
            $this->bump(1);
            return;
        }
        $el = $this->els[$this->sel];
        $f = $this->field;
        $this->app->ask(self::FIELDS[$f] . ' : nouveau n° de 0 à 255 (actuel ' . T::$raw[$el][$f] . ') : ', '', function ($val) use ($el, $f): void {
            if ($val === null || trim($val) === '') {
                return;
            }
            if (!preg_match('/^\s*(\d{1,3})\s*$/', $val, $m) || (int) $m[1] > 255) {
                $this->app->error('Nombre entre 0 et 255.');
                return;
            }
            $v = T::$raw[$el];
            $v[$f] = (int) $m[1];
            $this->setEl($el, $v);
        });
    }

    /** Enregistre les couleurs actuelles comme thème : il apparaît ensuite dans la liste des thèmes. */
    private function saveAs(): void
    {
        $s = $this->app->settings;
        $default = $s->customName() ?? 'Mon thème';
        if ($s->customName() === null) {
            for ($i = 2, $base = $default; isset($s->v['customThemes'][$default]); $i++) {
                $default = $base . ' ' . $i;
            }
        }
        $this->app->ask('Nom du thème : ', $default, function ($name) use ($s): void {
            if ($name === null) {
                return;
            }
            $name = trim(preg_replace('/[\x00-\x1F]/', '', $name));
            if ($name === '' || mb_strlen($name) > 30) {
                $this->app->error('Le nom doit faire entre 1 et 30 caractères.');
                return;
            }
            $do = function () use ($s, $name): void {
                $s->saveTheme($name);
                $this->app->applyTheme();
                $this->app->info('Thème « ' . $name . ' » enregistré : il est dans la liste des thèmes (Paramètres > Thème).');
            };
            if (isset($s->v['customThemes'][$name]) && $s->customName() !== $name) {
                $this->app->confirm('Le thème « ' . $name . ' » existe déjà. Le remplacer ?', $do);
            } else {
                $do();
            }
        });
    }

    private function resetEl(): void
    {
        $s = $this->app->settings;
        $colors = $s->v['colors'];
        unset($colors[$this->els[$this->sel]]);
        $s->set('colors', $colors);
        $this->app->applyTheme();
    }
}
