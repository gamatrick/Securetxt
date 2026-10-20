<?php

namespace Securetxt;

use Closure;
use RuntimeException;

interface View
{
    /** @return array{0:list<string>,1:array{0:int,1:int}|null} lignes + position du curseur */
    public function render(int $w, int $h): array;
    public function handle(string $key): void;
    public function perform(string $action): void;
    public function mouse(string $kind, int $x, int $y, string $mods): void;
    /** true = l'écran veut toutes les touches (pas de raccourcis globaux). */
    public function rawKeys(): bool;
}

final class App
{
    public Vault $vault;
    public Settings $settings;
    public Keys $keys;
    public Clipboard $clip;
    public View $view;
    public ?TreeView $tree = null;
    public ?Prompt $prompt = null;
    /** taille de la zone de l'interface (sans les marges) */
    public int $w = 80;
    public int $h = 24;
    /** nombre de clics rapprochés au même endroit (1, 2 ou 3) */
    public int $clicks = 1;
    /** @var list<array{0:string,1:int,2:int}> notes parentes (pour revenir avec Échap) */
    public array $history = [];

    private Terminal $term;
    private Screen $screen;
    private string $flash = '';
    private bool $flashError = false;
    private bool $running = true;
    private array $linkCache = [];
    private int $linkVersion = -1;
    /** @var list<array{0:int,1:int,2:string}> zones cliquables de la barre du bas */
    private array $hits = [];
    private float $lastClickT = 0.0;
    private string $lastClickPos = '';

    public function __construct(Vault $vault, Terminal $term, public string $appDir)
    {
        $this->vault = $vault;
        $this->term = $term;
        $this->screen = new Screen($term);
        $this->settings = new Settings($vault->dir() . DIRECTORY_SEPARATOR . 'settings.json');
        $this->keys = new Keys($this->settings->v['keys']);
        $this->clip = new Clipboard($term);
        $this->applyTheme();
    }

    public function applyTheme(): void
    {
        [$base, $colors] = $this->settings->resolveTheme();
        T::apply($base, $colors);
    }

    public function run(): void
    {
        $this->term->start();
        $this->term->setMouse($this->settings->v['mouse']);
        $this->relayout();
        $this->view = new UnlockView($this);
        $redraw = true;
        while ($this->running) {
            if ($redraw) {
                $this->draw();
                $redraw = false;
            }
            $keys = $this->term->readKeys();
            if (!$keys) {
                continue;
            }
            foreach ($this->coalesce($keys) as $k) {
                if ($k === 'RESIZE') {
                    $this->relayout();
                    continue;
                }
                $this->dispatch($k);
                if (!$this->running) {
                    break;
                }
            }
            $redraw = true;
        }
        $this->vault->lock();
        $this->term->stop();
    }

    /** Si beaucoup de caractères reçus d'un coup dans l'éditeur = un collage : on les insère en une fois. */
    private function coalesce(array $keys): array
    {
        if (count($keys) < 3 || $this->prompt !== null || !($this->view instanceof EditorView)) {
            return $keys;
        }
        $out = [];
        $pend = [];
        $buf = '';
        $flush = function () use (&$out, &$pend, &$buf): void {
            if (count($pend) > 1) {
                $out[] = 'PASTE:' . $buf;
            } else {
                array_push($out, ...$pend);
            }
            $pend = [];
            $buf = '';
        };
        foreach ($keys as $k) {
            if (mb_strlen($k) === 1 || $k === 'ENTER' || $k === 'TAB') {
                $pend[] = $k;
                $buf .= $k === 'ENTER' ? "\n" : ($k === 'TAB' ? "\t" : $k);
            } else {
                $flush();
                $out[] = $k;
            }
        }
        $flush();
        return $out;
    }

    public function relayout(): void
    {
        [$this->w, $this->h] = $this->term->size();
        $this->screen->reset($this->w, $this->h);
    }

    public function setMouse(bool $on): void
    {
        $this->term->setMouse($on);
    }

    private function dispatch(string $k): void
    {
        $isMouse = strncmp($k, 'MOUSE:', 6) === 0;
        if (!$isMouse || strpos($k, ':press:') !== false) {
            $this->flash = '';
        }
        try {
            if ($isMouse) {
                $this->mouse($k);
                return;
            }
            if ($this->prompt !== null) {
                $this->prompt->handle($k);
                return;
            }
            if (!$this->view->rawKeys() && ($a = $this->keys->action('global', $k)) !== null) {
                $this->performGlobal($a);
                return;
            }
            $this->view->handle($k);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
        } catch (\Throwable $e) {
            // bug : on l'affiche au lieu de quitter (et de perdre la note en cours)
            $this->error('Erreur interne : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        }
    }

    private function mouse(string $k): void
    {
        [, $kind, $x, $y, $mods] = explode(':', $k, 5);
        $x = (int) $x;
        $y = (int) $y;
        if ($this->prompt !== null || $x < 0 || $x >= $this->w || $y < 0 || $y >= $this->h) {
            return;
        }
        if ($kind === 'press') {
            $now = microtime(true);
            $pos = $x . ',' . $y;
            $this->clicks = ($now - $this->lastClickT < 0.4 && $pos === $this->lastClickPos) ? min(3, $this->clicks + 1) : 1;
            $this->lastClickT = $now;
            $this->lastClickPos = $pos;
            if ($y === $this->h - 1) {
                foreach ($this->hits as [$x0, $x1, $item]) {
                    if ($x >= $x0 && $x < $x1) {
                        $this->activate($item);
                        return;
                    }
                }
            }
        }
        $this->view->mouse($kind, $x, $y, $mods);
    }

    /** Élément de la barre du bas : un id d'action, ou "key:<touche>". */
    private function activate(string $item): void
    {
        if (strncmp($item, 'key:', 4) === 0) {
            $this->dispatch(substr($item, 4));
        } elseif ((Keys::ACTIONS[$item][0] ?? '') === 'global') {
            $this->performGlobal($item);
        } else {
            $this->view->perform($item);
        }
    }

    public function performGlobal(string $a): void
    {
        switch ($a) {
            case 'app.quit':
                $this->quit();
                return;
            case 'app.settings':
                $this->openSettings($this->view);
                return;
            case 'app.lock':
                $this->lock();
                return;
            case 'app.search':
                if ($this->view instanceof TreeView || $this->view instanceof EditorView) {
                    $this->openSearch($this->view);
                }
                return;
        }
    }

    public function draw(): void
    {
        if ($this->w < 30 || $this->h < 6) {
            $rows = array_fill(0, $this->h, (new Row($this->w, T::$c['text']))->done());
            $rows[0] = (new Row($this->w, T::$c['error']))->add(' Fenêtre trop petite')->done();
            $this->screen->present($rows, null);
            return;
        }
        [$rows, $cursor] = $this->view->render($this->w, $this->h);
        if ($this->prompt !== null) {
            [$rows[$this->h - 1], $cx] = $this->prompt->render($this->w);
            $cursor = [$cx, $this->h - 1];
            $this->hits = [];
        } elseif ($this->flash !== '') {
            $rows[$this->h - 1] = (new Row($this->w, $this->flashError ? T::$c['error'] : T::$c['flash']))
                ->add(' ' . $this->flash)->done();
            $this->hits = [];
        }
        $this->screen->present($rows, $cursor);
    }

    // -----------------------------messages-------------------------------

    public function info(string $m): void { $this->flash = $m; $this->flashError = false; }
    public function error(string $m): void { $this->flash = $m; $this->flashError = true; }

    public function ask(string $label, string $initial, Closure $done, ?Closure $completer = null): void
    {
        $this->prompt = new Prompt($this, $label, $initial, $done, false, $completer);
    }

    public function confirm(string $question, Closure $yes): void
    {
        $this->prompt = new Prompt($this, $question . ' (o/n) ', '', function ($ok) use ($yes): void {
            if ($ok === true) {
                $yes();
            }
        }, true);
    }

    // ------------------------------barres------------------------------

    public function header(string $left, string $right = ''): string
    {
        $r = new Row($this->w, T::$c['bar']);
        $r->add($left);
        $sp = $this->w - $r->used() - mb_strlen($right);
        if ($sp > 0) {
            $r->add(str_repeat(' ', $sp));
        }
        return $r->add($right)->done();
    }

    /**
     * Barre du bas cliquable. Chaque élément : [id d'action ou "key:<touche>", libellé].
     * Les éléments qui ne tiennent pas dans la largeur sont omis.
     */
    public function footer(array $items): string
    {
        $r = new Row($this->w, T::$c['status']);
        $this->hits = [];
        foreach ($items as [$item, $label]) {
            $k = strncmp($item, 'key:', 4) === 0 ? Keys::label(substr($item, 4), true) : $this->keys->display($item, true);
            if ($k === '') {
                continue;
            }
            $need = 1 + mb_strlen($k) + 2 + 1 + mb_strlen($label);
            if ($r->used() + $need > $this->w) {
                break;
            }
            $x0 = $r->used() + 1;
            $r->add(' ')->add(' ' . $k . ' ', T::$c['statusKey'])->add(' ' . $label);
            $this->hits[] = [$x0, $r->used(), $item];
        }
        return $r->done();
    }

    // --------------------------navigation----------------------------------

    public function onUnlocked(): void
    {
        $this->history = [];
        // après un verrouillage, l'arborescence garde ses dossiers ouverts / fermés
        $this->tree ??= new TreeView($this);
        $this->view = $this->tree;
    }

    public function lock(): void
    {
        $this->saveCurrent();
        $this->vault->lock();
        $this->history = [];
        $this->tree?->forget();
        $this->view = new UnlockView($this);
    }

    public function quit(): void
    {
        $this->saveCurrent();
        $this->running = false;
    }

    private function saveCurrent(): void
    {
        foreach ([$this->view, $this->view instanceof SettingsView ? $this->view->back : null] as $v) {
            if ($v instanceof EditorView) {
                $v->save();
            }
        }
    }

    public function openSettings(View $back): void
    {
        if ($back instanceof UnlockView || $back instanceof SettingsView || $back instanceof ColorView) {
            return;
        }
        if ($back instanceof EditorView) {
            $back->save();
        }
        $this->view = new SettingsView($this, $back);
    }

    public function openNote(string $id, int $line = 0, int $col = 0): void
    {
        $this->history = [];
        $this->view = new EditorView($this, $id, $line, $col);
    }

    /** Résolution d'un lien, mise en cache tant que l'arborescence ne change pas. */
    public function resolve(string $target, string $folder): ?string
    {
        $v = $this->vault->version();
        if ($v !== $this->linkVersion) {
            $this->linkCache = [];
            $this->linkVersion = $v;
        }
        $k = $folder . "\0" . $target;
        if (!array_key_exists($k, $this->linkCache)) {
            $this->linkCache[$k] = $this->vault->resolveLink($target, $folder);
        }
        return $this->linkCache[$k];
    }

    /** Ouvre la note enfant d'un [[lien]], ou la crée si elle n'existe pas. */
    public function followLink(string $target, EditorView $from): void
    {
        $from->save();
        $id = $this->resolve($target, $from->folder());
        if ($id === null) {
            $t = Vault::normalize($target);
            $path = (strpos($t, '/') === false && $from->folder() !== '') ? $from->folder() . '/' . $t : $t;
            if (($err = Vault::validate($path)) !== null) {
                $this->error($err);
                return;
            }
            $id = $this->vault->createNote($path);
            $this->info('Note créée : ' . $path);
        }
        if ($id === $from->id) {
            $this->info('Ce lien pointe vers la note ouverte.');
            return;
        }
        $this->history[] = [$from->id, $from->cy, $from->cx];
        $this->view = new EditorView($this, $id);
    }

    /** Échap dans l'éditeur : retour à la note parente, sinon à l'arborescence. */
    public function closeEditor(EditorView $e): void
    {
        $e->save();
        while ($this->history) {
            [$id, $cy, $cx] = array_pop($this->history);
            if ($this->vault->path($id) !== null) {
                $this->view = new EditorView($this, $id, $cy, $cx);
                return;
            }
        }
        $this->tree->focus('note', $this->vault->path($e->id) ?? '');
        $this->view = $this->tree;
    }

    public function openSearch(View $back): void
    {
        if ($back instanceof EditorView) {
            $back->save();
        }
        $this->view = new ListView($this, $back, 'search');
    }

    public function openBacklinks(EditorView $from): void
    {
        $from->save();
        $this->view = new ListView($this, $from, 'backlinks', $from->id);
    }

    public function openFromList(View $back, string $id, int $line, int $col): void
    {
        if ($back instanceof EditorView) {
            if ($back->id === $id) {
                $back->moveTo($line, $col);
                $this->view = $back;
                return;
            }
            $this->history[] = [$back->id, $back->cy, $back->cx];
            $this->view = new EditorView($this, $id, $line, $col);
            return;
        }
        $this->openNote($id, $line, $col);
    }
}

/** Saisie d'une ligne (ou question o/n) dans la barre du bas. Tab complète les chemins. */
final class Prompt
{
    private string $value;
    private int $pos;
    private ?array $cands = null;
    private int $ci = -1;
    private bool $lastTab = false;

    public function __construct(
        private App $app,
        private string $label,
        string $initial,
        private Closure $done,
        private bool $confirm,
        private ?Closure $completer = null
    ) {
        $this->value = $initial;
        $this->pos = mb_strlen($initial);
    }

    private function finish($result): void
    {
        $this->app->prompt = null;
        ($this->done)($result);
    }

    private function insert(string $s): void
    {
        $this->value = mb_substr($this->value, 0, $this->pos) . $s . mb_substr($this->value, $this->pos);
        $this->pos += mb_strlen($s);
    }

    public function handle(string $k): void
    {
        if ($this->confirm) {
            $this->finish(in_array($k, ['o', 'O', 'y', 'Y'], true));
            return;
        }
        $wasTab = $this->lastTab;
        $this->lastTab = false;
        if (strncmp($k, 'PASTE:', 6) === 0) {
            $this->insert(strtok(substr($k, 6), "\n") ?: '');
            return;
        }
        switch ($k) {
            case 'ENTER': $this->finish($this->value); return;
            case 'ESC': $this->finish(null); return;
            case 'LEFT': $this->pos = max(0, $this->pos - 1); return;
            case 'RIGHT': $this->pos = min(mb_strlen($this->value), $this->pos + 1); return;
            case 'HOME': $this->pos = 0; return;
            case 'END': $this->pos = mb_strlen($this->value); return;
            case 'TAB': $this->complete($wasTab); return;
            case 'BACKSPACE':
                if ($this->pos > 0) {
                    $this->value = mb_substr($this->value, 0, $this->pos - 1) . mb_substr($this->value, $this->pos);
                    $this->pos--;
                }
                return;
            case 'C-BACKSPACE':
            case 'C-S-BACKSPACE':
                $this->value = mb_substr($this->value, $this->pos);
                $this->pos = 0;
                return;
            case 'DEL':
                $this->value = mb_substr($this->value, 0, $this->pos) . mb_substr($this->value, $this->pos + 1);
                return;
            case 'C-V':
                $this->insert(strtok($this->app->clip->paste(), "\n") ?: '');
                return;
        }
        if (mb_strlen($k) === 1) {
            $this->insert($k);
        }
    }

    private function complete(bool $cycle): void
    {
        $this->lastTab = true;
        if ($this->completer === null) {
            return;
        }
        if ($cycle && $this->cands) {
            $this->ci = ($this->ci + 1) % count($this->cands);
            $this->value = $this->cands[$this->ci];
        } else {
            $c = ($this->completer)($this->value);
            if (!$c) {
                $this->cands = null;
                return;
            }
            if (count($c) === 1) {
                $this->value = $c[0];
                $this->cands = null;
            } else {
                $cp = $c[0];
                foreach ($c as $s) {
                    while ($cp !== '' && mb_stripos($s, $cp) !== 0) {
                        $cp = mb_substr($cp, 0, -1);
                    }
                }
                if (mb_strlen($cp) > mb_strlen($this->value)) {
                    $this->value = $cp;
                }
                $this->cands = $c;
                $this->ci = -1;
            }
        }
        $this->pos = mb_strlen($this->value);
    }

    /** @return array{0:string,1:int} */
    public function render(int $w): array
    {
        $r = new Row($w, T::$c['input']);
        $label = ' ' . $this->label;
        $r->add($label, T::$c['inputLabel']);
        if ($this->confirm) {
            return [$r->done(), min($w - 1, mb_strlen($label))];
        }
        $hint = $this->cands ? '  [Tab : ' . count($this->cands) . ' choix]' : '';
        $room = max(1, $w - mb_strlen($label) - 1 - mb_strlen($hint));
        $start = max(0, $this->pos - $room + 1);
        $r->add(mb_substr($this->value, $start, $room));
        if ($hint !== '') {
            $r->add(str_repeat(' ', max(0, $w - $r->used() - mb_strlen($hint))))->add($hint, T::$c['inputLabel']);
        }
        return [$r->done(), min($w - 1, mb_strlen($label) + $this->pos - $start)];
    }
}
