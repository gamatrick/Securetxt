<?php

namespace Securetxt;

// =====================================================================
//  Écran de démarrage : création du coffre ou mot de passe
// =====================================================================
final class UnlockView implements View
{
    private const LOGO = [
        '█▀▀ █▀▀ █▀▀ █ █ █▀█ █▀▀ ▀█▀ █ █ ▀█▀',
        '▀▀█ █▀▀ █   █ █ █▀▄ █▀▀  █   █   █ ',
        '▀▀▀ ▀▀▀ ▀▀▀ ▀▀▀ ▀ ▀ ▀▀▀  ▀  ▀ ▀  ▀ ',
    ];

    private string $pw = '';
    private string $first = '';
    private int $stage;          // 0 = déverrouiller, 1 = nouveau mot de passe, 2 = confirmation
    private string $msg;
    private bool $err = false;

    public function __construct(private App $app)
    {
        $this->stage = $app->vault->exists() ? 0 : 1;
        $this->msg = $this->stage === 0
            ? 'Entre ton mot de passe.'
            : 'Nouveau coffre : choisis un mot de passe (6 car. min).';
    }

    public function rawKeys(): bool { return true; }
    public function perform(string $action): void {}
    public function mouse(string $kind, int $x, int $y, string $mods): void {}

    public function render(int $w, int $h): array
    {
        $c = T::$c;
        $lines = [];
        if ($h >= 14) {
            foreach (self::LOGO as $l) {
                $lines[] = [$l, $c['logo']];
            }
            $lines[] = ['', null];
        }
        $lines[] = ['SECURETXT  v0.3.4  - notes chiffrées', $c['dim']];
        $lines[] = ['Coffre : ' . $this->app->vault->dir(), $c['dim']];
        $lines[] = ['', null];
        $lines[] = ['READY', $c['heading']];
        $lines[] = [$this->msg, $this->err ? $c['error'] : null];
        $label = $this->stage === 2 ? 'CONFIRMATION> ' : 'MOT DE PASSE> ';
        $lines[] = [$label . str_repeat('*', mb_strlen($this->pw)), null];

        $x0 = max(2, intdiv($w - mb_strlen(self::LOGO[0]), 2));
        $y0 = max(1, intdiv($h - 2 - count($lines), 2) + 1);
        $out = [$this->app->header(' SECURETXT', 'v0.3.4 ')];
        for ($y = 1; $y < $h - 1; $y++) {
            $row = new Row($w, $c['text']);
            $line = $lines[$y - $y0] ?? null;
            if ($line !== null) {
                $row->add(str_repeat(' ', $x0))->add($line[0], $line[1]);
            }
            $out[] = $row->done();
        }
        $out[] = $this->app->footer([['key:ENTER', 'Valider'], ['key:ESC', 'Quitter']]);
        $cursor = [min($w - 1, $x0 + mb_strlen($label) + mb_strlen($this->pw)), $y0 + count($lines) - 1];
        return [$out, $cursor];
    }

    public function handle(string $k): void
    {
        if ($k === 'ESC' || $k === 'C-Q') {
            $this->app->quit();
            return;
        }
        if ($k === 'BACKSPACE') {
            $this->pw = mb_substr($this->pw, 0, -1);
            return;
        }
        if ($k === 'ENTER') {
            $this->submit();
            return;
        }
        if (strncmp($k, 'PASTE:', 6) === 0) {
            $this->pw .= strtok(substr($k, 6), "\n") ?: '';
            return;
        }
        if (mb_strlen($k) === 1) {
            $this->pw .= $k;
        }
    }

    private function submit(): void
    {
        $pw = $this->pw;
        $this->pw = '';
        $this->err = false;
        if ($this->stage === 0) {
            $this->msg = 'Déverrouillage...';
            $this->app->draw();
            $ok = $this->app->vault->unlock($pw);
            sodium_memzero($pw);
            if ($ok) {
                $this->app->onUnlocked();
                return;
            }
            $this->msg = 'Mot de passe incorrect.';
            $this->err = true;
            return;
        }
        if ($this->stage === 1) {
            if (mb_strlen($pw) < 6) {
                $this->msg = 'Au moins 6 caractères, s\'il te plaît.';
                $this->err = true;
                return;
            }
            $this->first = $pw;
            $this->stage = 2;
            $this->msg = 'Retape le même mot de passe.';
            return;
        }
        if ($pw !== $this->first) {
            $this->first = '';
            $this->stage = 1;
            $this->msg = 'Les deux mots de passe sont différents. Recommence.';
            $this->err = true;
            return;
        }
        $this->msg = 'Création du coffre...';
        $this->app->draw();
        $this->app->vault->create($pw);
        sodium_memzero($pw);
        $this->first = '';
        Welcome::seed($this->app->vault);
        $this->app->onUnlocked();
    }
}

// =====================================================================
//  Arborescence : dossiers puis notes, triés par ordre alphabétique
// =====================================================================
final class TreeView implements View
{
    /** @var list<array<string,mixed>> lignes affichées */
    private array $rows = [];
    /** État du coffre lors du dernier calcul (structure + contenu). */
    private string $stamp = '';
    /** @var array<string,bool> dossiers fermés */
    private array $collapsed = [];
    /** @var array<string,bool> branches de notes ouvertes (clé : chaîne d'ids "a>b>c"), fermées par défaut */
    private array $expanded = [];
    /** @var array<string,list<string>> note => notes enfants (ses liens [[...]]) */
    private array $children = [];
    /** @var array<string,list<string>> note => notes parentes */
    private array $parents = [];
    /** @var array<string,array{0:string,1:int,2:list<string>}> liens déjà analysés par note */
    private array $linkCache = [];
    private int $sel = 0;
    private int $top = 0;
    private int $bodyH = 20;
    private bool $follow = true;
    private ?string $want = null;
    /** Au lancement de l'application, tous les dossiers sont fermés. */
    private bool $firstBuild = true;

    public function __construct(private App $app)
    {
    }

    public function rawKeys(): bool { return false; }

    /** Verrouillage : on oublie les noms et les liens, on garde seulement l'état des dossiers et branches. */
    public function forget(): void
    {
        $this->rows = [];
        $this->children = $this->parents = $this->linkCache = [];
        $this->stamp = '';
    }

    /** Sélectionne un élément (et déplie ce qu'il faut pour le voir). */
    public function focus(string $type, string $path): void
    {
        for ($p = Vault::parent($path); $p !== ''; $p = Vault::parent($p)) {
            unset($this->collapsed[$p]);
        }
        $this->want = $type . ':' . $path;
        $this->stamp = '';
        $this->follow = true;
    }

    private function currentStamp(): string
    {
        $v = $this->app->vault;
        return $v->version() . ':' . $v->contentVersion();
    }

    private function sync(): void
    {
        if ($this->stamp !== $this->currentStamp()) {
            $cur = $this->rows[$this->sel] ?? null;
            if ($this->want === null && $cur !== null) {
                $this->want = $cur['key'];
            }
            $this->rebuild();
            $this->stamp = $this->currentStamp();
        }
        if ($this->want !== null) {
            $i = $this->findRow($this->want);
            if ($i === null && strncmp($this->want, 'note:', 5) === 0) {
                $this->reveal(substr($this->want, 5));
                $this->rebuild();
                $i = $this->findRow($this->want);
            }
            if ($i !== null) {
                $this->sel = $i;
            }
            $this->want = null;
        }
        $this->sel = max(0, min($this->sel, count($this->rows) - 1));
    }

    private function findRow(string $want): ?int
    {
        foreach ($this->rows as $i => $r) {
            if ($r['key'] === $want || $r['t'] . ':' . $r['path'] === $want) {
                return $i;
            }
        }
        return null;
    }

    /** Ouvre les branches qui mènent à une note rangée sous son parent. */
    private function reveal(string $path): void
    {
        $v = $this->app->vault;
        $id = $v->findByPath($path);
        if ($id === null) {
            return;
        }
        $chain = [$id];
        $seen = [$id => true];
        for ($cur = $id; ; ) {
            $folder = Vault::parent((string) $v->path($cur));
            $next = null;
            foreach ($this->sortByName($this->parents[$cur] ?? []) as $p) {
                if (!isset($seen[$p]) && Vault::parent((string) $v->path($p)) === $folder) {
                    $next = $p;
                    break;
                }
            }
            if ($next === null) {
                break;
            }
            array_unshift($chain, $next);
            $seen[$next] = true;
            $cur = $next;
        }
        for ($k = 1; $k < count($chain); $k++) {
            $this->expanded[implode('>', array_slice($chain, 0, $k))] = true;
        }
    }

    /** @param list<string> $ids @return list<string> */
    private function sortByName(array $ids): array
    {
        $v = $this->app->vault;
        usort($ids, fn ($a, $b) => Text::cmp(Vault::base((string) $v->path($a)), Vault::base((string) $v->path($b))));
        return $ids;
    }

    /** Notes vers lesquelles pointe la note $id (ses enfants), sans les images ni les liens vers des notes inexistantes. */
    private function linksOf(string $id, string $path): array
    {
        $v = $this->app->vault;
        $content = $v->read($id);
        $c = $this->linkCache[$id] ?? null;
        if ($c !== null && $c[0] === $content && $c[1] === $v->version()) {
            return $c[2];
        }
        $kids = [];
        if (strpos($content, '[[') !== false && preg_match_all(Vault::LINK_RE, $content, $m)) {
            foreach ($m[1] as $t) {
                if (strncmp(ltrim($t), 'img:', 4) === 0) {
                    continue;
                }
                $k = $this->app->resolve($t, Vault::parent($path));
                if ($k !== null && $k !== $id) {
                    $kids[$k] = true;
                }
            }
        }
        $kids = $this->sortByName(array_map('strval', array_keys($kids)));
        $this->linkCache[$id] = [$content, $v->version(), $kids];
        return $kids;
    }

    private function rebuild(): void
    {
        $v = $this->app->vault;
        $folders = [];
        foreach ($v->explicitFolders() as $f) {
            for ($p = $f; $p !== ''; $p = Vault::parent($p)) {
                $folders[$p] = true;
            }
        }
        $byFolder = [];
        $this->children = $this->parents = [];
        foreach ($v->notes() as $id => $path) {
            $id = (string) $id;
            $par = Vault::parent($path);
            for ($p = $par; $p !== ''; $p = Vault::parent($p)) {
                $folders[$p] = true;
            }
            $byFolder[$par][] = $id;
            $this->children[$id] = $this->linksOf($id, $path);
            foreach ($this->children[$id] as $k) {
                $this->parents[$k][] = $id;
            }
        }
        if ($this->firstBuild) {
            $this->firstBuild = false;
            foreach (array_keys($folders) as $f) {
                $this->collapsed[(string) $f] = true;
            }
        }
        $subBy = [];
        foreach (array_keys($folders) as $f) {
            $f = (string) $f;
            $subBy[Vault::parent($f)][] = $f;
        }
        foreach ($subBy as &$list) {
            usort($list, fn ($a, $b) => Text::cmp(Vault::base($a), Vault::base($b)));
        }
        unset($list);
        $rootsBy = [];
        foreach ($byFolder as $folder => $ids) {
            $rootsBy[(string) $folder] = $this->rootsOf($ids);
        }
        $this->rows = [];
        $this->walk('', 0, $subBy, $rootsBy);
    }

    /**
     * Notes affichées directement dans leur dossier : celles qui n'ont pas de parent dans ce même dossier
     * (les autres sont rangées sous leur parent). En cas de boucle, une note de la boucle reste visible.
     * @param list<string> $ids @return list<string>
     */
    private function rootsOf(array $ids): array
    {
        $in = array_flip($ids);
        $roots = [];
        foreach ($ids as $id) {
            $hasParent = false;
            foreach ($this->parents[$id] ?? [] as $p) {
                if (isset($in[$p])) {
                    $hasParent = true;
                    break;
                }
            }
            if (!$hasParent) {
                $roots[] = $id;
            }
        }
        $seen = [];
        $visit = function (string $id) use (&$visit, &$seen, $in): void {
            if (isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            foreach ($this->children[$id] ?? [] as $k) {
                if (isset($in[$k])) {
                    $visit($k);
                }
            }
        };
        foreach ($roots as $r) {
            $visit($r);
        }
        foreach ($this->sortByName($ids) as $id) {
            if (!isset($seen[$id])) {
                $roots[] = $id;
                $visit($id);
            }
        }
        return $this->sortByName($roots);
    }

    private function walk(string $folder, int $depth, array &$subBy, array &$rootsBy): void
    {
        foreach ($subBy[$folder] ?? [] as $f) {
            $this->rows[] = ['t' => 'folder', 'path' => $f, 'name' => Vault::base($f), 'key' => 'folder:' . $f,
                'x' => 1 + 2 * $depth, 'id' => null];
            if (empty($this->collapsed[$f])) {
                $this->walk($f, $depth + 1, $subBy, $rootsBy);
            }
        }
        foreach ($rootsBy[$folder] ?? [] as $id) {
            $this->emitNote($id, 1 + 2 * $depth, false, $id, [$id => true], false);
        }
    }

    /** Ajoute une note et, si sa branche est ouverte, ses enfants en dessous (|- ...). */
    private function emitNote(string $id, int $x, bool $branch, string $chain, array $ancestors, bool $cycle): void
    {
        $path = (string) $this->app->vault->path($id);
        $kids = $cycle ? [] : ($this->children[$id] ?? []);
        $open = $kids && !empty($this->expanded[$chain]);
        $name = Vault::base($path);
        if ($branch) {
            // enfant rangé dans un autre dossier que la note du dessus : on montre son chemin
            $parentId = substr($chain, 0, -strlen($id) - 1);
            $parentId = ($p = strrpos($parentId, '>')) === false ? $parentId : substr($parentId, $p + 1);
            if (Vault::parent($path) !== Vault::parent((string) $this->app->vault->path($parentId))) {
                $name = $path;
            }
        }
        $mx = $branch ? $x + 3 : $x;
        $this->rows[] = ['t' => 'note', 'id' => $id, 'path' => $path, 'name' => $name, 'key' => 'chain:' . $chain,
            'chain' => $chain, 'x' => $x, 'mx' => $mx, 'branch' => $branch, 'kids' => (bool) $kids, 'open' => $open, 'cycle' => $cycle];
        if (!$open) {
            return;
        }
        $nameCol = $mx + ($branch ? 2 : 4);
        foreach ($kids as $k) {
            $this->emitNote($k, $nameCol + 2, true, $chain . '>' . $k, $ancestors + [$k => true], isset($ancestors[$k]));
        }
    }

    public function render(int $w, int $h): array
    {
        $c = T::$c;
        $this->sync();
        $this->bodyH = $h - 2;
        if ($this->follow) {
            if ($this->sel < $this->top) {
                $this->top = $this->sel;
            }
            if ($this->sel >= $this->top + $this->bodyH) {
                $this->top = $this->sel - $this->bodyH + 1;
            }
        }
        $this->top = max(0, min($this->top, count($this->rows) - 1));
        $out = [$this->app->header(' SECURETXT', count($this->app->vault->notes()) . ' notes ')];
        for ($i = 0; $i < $this->bodyH; $i++) {
            $idx = $this->top + $i;
            $r = $this->rows[$idx] ?? null;
            if ($r === null) {
                $row = new Row($w, $c['text']);
                if ($i === 0 && !$this->rows) {
                    $row->add('  Coffre vide. N = nouvelle note, I = importer des fichiers.', $c['dim']);
                }
                $out[] = $row->done();
                continue;
            }
            $selected = $idx === $this->sel;
            $icon = $selected ? null : $c['folderIcon'];
            $row = new Row($w, $selected ? $c['sel'] : $c['text']);
            $row->add(str_repeat(' ', $r['x']));
            if ($r['t'] === 'folder') {
                $row->add(empty($this->collapsed[$r['path']]) ? '[-] ' : '[+] ', $icon);
                $row->add($r['name'] . '/', $selected ? null : $c['folder']);
            } else {
                if ($r['branch']) {
                    $row->add('|- ', $icon);
                }
                if ($r['kids']) {
                    // ► enfants cachés, ▼ enfants affichés (les dossiers gardent [+] / [-])
                    $mark = $r['open'] ? '▼' : '►';
                    $row->add($r['branch'] ? $mark . ' ' : ' ' . $mark . '  ', $selected ? null : $c['kidsIcon']);
                } elseif (!$r['branch']) {
                    $row->add('    ');
                }
                $row->add($r['name'], ($selected || !$r['branch']) ? null : $c['treeChild']);
                if ($r['cycle']) {
                    $row->add(' ↺', $selected ? null : $c['dim']);
                }
            }
            $out[] = $row->done();
        }
        $cur = $this->rows[$this->sel] ?? null;
        $kidsHint = [];
        if ($cur !== null && $cur['t'] === 'note' && $cur['kids']) {
            $kidsHint = [$cur['open'] ? ['tree.collapse', 'Cacher les enfants'] : ['tree.expand', 'Voir les enfants']];
        }
        $out[] = $this->app->footer([
            ['tree.open', 'Ouvrir'], ...$kidsHint, ['tree.newNote', 'Note'], ['tree.newFolder', 'Dossier'],
            ['tree.settings', 'Réglages'], ['app.search', 'Chercher'], ['tree.rename', 'Renommer'],
            ['tree.delete', 'Effacer'], ['tree.import', 'Importer'], ['tree.export', 'Exporter'],
            ['tree.lock', 'Verrou'], ['tree.quit', 'Quitter'],
        ]);
        return [$out, null];
    }

    private function current(): ?array
    {
        return $this->rows[$this->sel] ?? null;
    }

    /** Dossier dans lequel créer : le dossier sélectionné, ou celui de la note sélectionnée. */
    private function currentFolder(): string
    {
        $r = $this->current();
        if ($r === null) {
            return '';
        }
        return $r['t'] === 'folder' ? $r['path'] : Vault::parent($r['path']);
    }

    public function handle(string $k): void
    {
        $this->follow = true;
        $n = count($this->rows);
        $page = max(1, $this->bodyH - 1);
        switch ($k) {
            case 'UP': $this->sel = max(0, $this->sel - 1); return;
            case 'DOWN': $this->sel = min($n - 1, $this->sel + 1); return;
            case 'PGUP': $this->sel = max(0, $this->sel - $page); return;
            case 'PGDN': $this->sel = min($n - 1, $this->sel + $page); return;
            case 'HOME': $this->sel = 0; return;
            case 'END': $this->sel = $n - 1; return;
        }
        if (($a = $this->app->keys->action('tree', $k)) !== null) {
            $this->perform($a);
        }
    }

    public function perform(string $a): void
    {
        $r = $this->current();
        switch ($a) {
            case 'tree.open':
                if ($r === null) {
                    return;
                }
                if ($r['t'] === 'folder') {
                    $this->toggle($r['path'], empty($this->collapsed[$r['path']]));
                } else {
                    $this->app->openNote($r['id']);
                }
                return;
            case 'tree.toggle':
                if ($r === null) {
                    return;
                }
                if ($r['t'] === 'folder') {
                    $this->toggle($r['path'], empty($this->collapsed[$r['path']]));
                } elseif ($r['kids']) {
                    $this->toggleNote($r['chain'], !$r['open']);
                }
                return;
            case 'tree.expand':
                if ($r === null) {
                    return;
                }
                if ($r['t'] === 'folder') {
                    $this->toggle($r['path'], false);
                } elseif ($r['kids']) {
                    $this->toggleNote($r['chain'], true);
                }
                return;
            case 'tree.collapse':
                if ($r === null) {
                    return;
                }
                if ($r['t'] === 'folder' && empty($this->collapsed[$r['path']])) {
                    $this->toggle($r['path'], true);
                } elseif ($r['t'] === 'note' && $r['open']) {
                    $this->toggleNote($r['chain'], false);
                } elseif ($r['t'] === 'note' && $r['branch']) {
                    $this->want = 'chain:' . substr($r['chain'], 0, strrpos($r['chain'], '>'));
                } elseif (Vault::parent($r['path']) !== '') {
                    $this->want = 'folder:' . Vault::parent($r['path']);
                }
                return;
            case 'tree.newNote': $this->newNote(); return;
            case 'tree.newFolder': $this->newFolder(); return;
            case 'tree.rename': $this->rename(); return;
            case 'tree.delete': $this->delete(); return;
            case 'tree.import': $this->import(); return;
            case 'tree.export': $this->export(false); return;
            case 'tree.exportAll': $this->export(true); return;
            case 'tree.search': $this->app->openSearch($this); return;
            case 'tree.settings': $this->app->openSettings($this); return;
            case 'tree.lock': $this->app->lock(); return;
            case 'tree.quit': $this->app->quit(); return;
        }
    }

    public function mouse(string $kind, int $x, int $y, string $mods): void
    {
        if ($kind === 'wheelup' || $kind === 'wheeldown') {
            $this->top = max(0, min(count($this->rows) - 1, $this->top + ($kind === 'wheelup' ? -3 : 3)));
            $this->follow = false;
            return;
        }
        if ($kind !== 'press' || $y < 1 || $y > $this->bodyH) {
            return;
        }
        $idx = $this->top + $y - 1;
        if (!isset($this->rows[$idx])) {
            return;
        }
        $this->sel = $idx;
        $this->follow = true;
        $r = $this->rows[$idx];
        if ($r['t'] === 'folder') {
            if (($x >= $r['x'] && $x < $r['x'] + 3) || $this->app->clicks >= 2) {
                $this->toggle($r['path'], empty($this->collapsed[$r['path']]));
            }
        } elseif ($r['kids'] && $x >= $r['x'] && $x < $r['mx'] + 3) {
            $this->toggleNote($r['chain'], !$r['open']);
        } elseif ($this->app->clicks >= 2) {
            $this->app->openNote($r['id']);
        }
    }

    private function toggle(string $path, bool $collapse): void
    {
        if ($collapse) {
            $this->collapsed[$path] = true;
        } else {
            unset($this->collapsed[$path]);
        }
        $this->want = 'folder:' . $path;
        $this->stamp = '';
    }

    private function toggleNote(string $chain, bool $open): void
    {
        if ($open) {
            $this->expanded[$chain] = true;
        } else {
            unset($this->expanded[$chain]);
        }
        $this->want = 'chain:' . $chain;
        $this->stamp = '';
    }

    private static function join(string $folder, string $name): string
    {
        return Vault::normalize($folder === '' ? $name : $folder . '/' . $name);
    }

    private function newNote(): void
    {
        $folder = $this->currentFolder();
        $this->app->ask('Nouvelle note : ' . ($folder === '' ? '' : $folder . '/'), '', function ($name) use ($folder): void {
            if ($name === null || trim($name) === '') {
                return;
            }
            $path = self::join($folder, $name);
            if (($err = Vault::validate($path)) !== null) {
                $this->app->error($err);
                return;
            }
            $id = $this->app->vault->findByPath($path) ?? $this->app->vault->createNote($path);
            $this->focus('note', $path);
            $this->app->openNote($id);
        });
    }

    private function newFolder(): void
    {
        $folder = $this->currentFolder();
        $this->app->ask('Nouveau dossier : ' . ($folder === '' ? '' : $folder . '/'), '', function ($name) use ($folder): void {
            if ($name === null || trim($name) === '') {
                return;
            }
            $path = self::join($folder, $name);
            if (($err = Vault::validate($path)) !== null) {
                $this->app->error($err);
                return;
            }
            $this->app->vault->createFolder($path);
            $this->focus('folder', $path);
        });
    }

    private function rename(): void
    {
        $r = $this->current();
        if ($r === null) {
            return;
        }
        $this->app->ask('Renommer en : ', $r['path'], function ($name) use ($r): void {
            if ($name === null) {
                return;
            }
            $path = Vault::normalize($name);
            if (($err = Vault::validate($path)) !== null) {
                $this->app->error($err);
                return;
            }
            if ($path === $r['path']) {
                return;
            }
            if ($r['t'] === 'folder') {
                $this->app->vault->renameFolder($r['path'], $path);
            } else {
                $this->app->vault->rename($r['id'], $path);
            }
            $this->focus($r['t'], $path);
            $this->app->info('Renommé. Pense à mettre à jour les liens [[...]] qui utilisaient l\'ancien nom.');
        });
    }

    private function delete(): void
    {
        $r = $this->current();
        if ($r === null) {
            return;
        }
        if ($r['t'] === 'note') {
            $this->app->confirm('Effacer la note « ' . $r['path'] . ' » ?', function () use ($r): void {
                $this->app->vault->deleteNote($r['id']);
                $this->app->info('Note effacée.');
            });
            return;
        }
        $count = count($this->app->vault->notesInFolder($r['path']));
        $this->app->confirm('Effacer le dossier « ' . $r['path'] . ' » et ses ' . $count . ' note(s) ?', function () use ($r): void {
            $this->app->vault->deleteFolder($r['path']);
            $this->app->info('Dossier effacé.');
        });
    }

    private function import(): void
    {
        $folder = $this->currentFolder();
        $dest = $folder === '' ? 'la racine' : $folder . '/';
        $this->app->ask('Importer dans ' . $dest . ' (fichier ou dossier) : ', Transfer::homeDir(), function ($src) use ($folder): void {
            if ($src === null || trim($src) === '') {
                return;
            }
            [$n, $skipped, $first] = Transfer::import($this->app->vault, Transfer::cleanPath($src), $folder);
            if ($first !== null) {
                $this->focus('note', $first);
            }
            $msg = $n . ' note(s) importée(s)';
            if ($skipped) {
                $msg .= ', ' . $skipped . ' fichier(s) ignoré(s) (pas du texte)';
            }
            $this->app->info($msg . '. Les originaux sont toujours en clair sur le disque : supprime-les si besoin.');
        }, fn (string $v): array => Transfer::complete($v));
    }

    private function export(bool $all): void
    {
        $v = $this->app->vault;
        $r = $this->current();
        if ($all) {
            $ids = array_keys($v->notes());
            $base = '';
            $label = 'tout le coffre';
        } elseif ($r === null) {
            return;
        } elseif ($r['t'] === 'note') {
            $ids = [$r['id']];
            $base = Vault::parent($r['path']);
            $label = '« ' . $r['path'] . ' »';
        } else {
            $ids = $v->notesInFolder($r['path']);
            $base = Vault::parent($r['path']);
            $label = '« ' . $r['path'] . '/ »';
        }
        if (!$ids) {
            $this->app->info('Rien à exporter.');
            return;
        }
        $this->app->ask('Exporter ' . $label . ' vers le dossier : ', Transfer::homeDir(), function ($dest) use ($ids, $base, $all): void {
            if ($dest === null || trim($dest) === '') {
                return;
            }
            $dest = rtrim(Transfer::cleanPath($dest), '/\\');
            if ($all) {
                $dest .= DIRECTORY_SEPARATOR . 'Securetxt-export-' . date('Y-m-d_His');
            }
            $this->app->confirm('Les fichiers exportés seront EN CLAIR (non chiffrés). Continuer ?', function () use ($ids, $base, $dest): void {
                $n = Transfer::export($this->app->vault, $ids, $base, $dest);
                $this->app->info($n . ' note(s) exportée(s) dans ' . $dest);
            });
        }, fn (string $v): array => Transfer::complete($v));
    }
}

// =====================================================================
//  Liste de résultats : recherche globale ou rétroliens
// =====================================================================
final class ListView implements View
{
    private const MAX = 500;

    private string $query = '';
    /** @var list<array{0:string,1:string,2:int,3:int,4:string,5:int}> id, chemin, ligne, colonne, texte, longueur */
    private array $results = [];
    private int $sel = 0;
    private int $top = 0;
    private int $bodyH = 20;
    private bool $follow = true;

    public function __construct(private App $app, private View $back, private string $mode, private ?string $target = null)
    {
        if ($mode === 'backlinks') {
            $this->backlinks();
        }
    }

    public function rawKeys(): bool { return false; }

    private function sortedNotes(): array
    {
        $paths = $this->app->vault->notes();
        uasort($paths, [Text::class, 'cmp']);
        return $paths;
    }

    private function search(): void
    {
        $this->results = [];
        $this->sel = $this->top = 0;
        $q = Text::fold(trim($this->query));
        if ($q === '') {
            return;
        }
        $qlen = mb_strlen($q);
        $v = $this->app->vault;
        foreach ($this->sortedNotes() as $id => $p) {
            if (strpos(Text::fold($p), $q) !== false) {
                $this->results[] = [$id, $p, -1, 0, '', 0];
            }
            $folded = $v->folded($id);
            if (strpos($folded, $q) === false) {
                continue;
            }
            $orig = explode("\n", $v->read($id));
            foreach (explode("\n", $folded) as $ln => $fl) {
                $b = strpos($fl, $q);
                if ($b === false) {
                    continue;
                }
                $this->results[] = [$id, $p, $ln, mb_strlen(substr($fl, 0, $b)), $orig[$ln] ?? '', $qlen];
                if (count($this->results) >= self::MAX) {
                    return;
                }
            }
        }
    }

    private function backlinks(): void
    {
        foreach ($this->sortedNotes() as $id => $p) {
            if ($id === $this->target) {
                continue;
            }
            $content = $this->app->vault->read($id);
            if (strpos($content, '[[') === false) {
                continue;
            }
            $folder = Vault::parent($p);
            foreach (explode("\n", $content) as $ln => $line) {
                if (strpos($line, '[[') === false || !preg_match_all(Vault::LINK_RE, $line, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                foreach ($m[1] as $i => [$t]) {
                    if ($this->app->resolve($t, $folder) === $this->target) {
                        $col = mb_strlen(substr($line, 0, $m[0][$i][1]));
                        $this->results[] = [$id, $p, $ln, $col, $line, mb_strlen($m[0][$i][0])];
                        break;
                    }
                }
            }
        }
    }

    public function render(int $w, int $h): array
    {
        $c = T::$c;
        $search = $this->mode === 'search';
        $count = count($this->results) . ($search && count($this->results) >= self::MAX ? '+' : '') . ' résultat(s) ';
        $out = [$this->app->header(
            $search ? ' RECHERCHE DANS TOUT LE COFFRE' : ' RÉTROLIENS → ' . $this->app->vault->path((string) $this->target),
            $count
        )];
        $input = new Row($w, $search ? $c['input'] : $c['text']);
        if ($search) {
            $input->add(' > ', $c['inputLabel'])->add($this->query);
        } else {
            $input->add(' Notes qui contiennent un lien vers cette note :', $c['dim']);
        }
        $out[] = $input->done();

        $this->bodyH = $h - 3;
        if ($this->follow) {
            if ($this->sel < $this->top) {
                $this->top = $this->sel;
            }
            if ($this->sel >= $this->top + $this->bodyH) {
                $this->top = $this->sel - $this->bodyH + 1;
            }
        }
        $this->top = max(0, min($this->top, count($this->results) - 1));
        for ($i = 0; $i < $this->bodyH; $i++) {
            $idx = $this->top + $i;
            $r = $this->results[$idx] ?? null;
            if ($r === null) {
                $row = new Row($w, $c['text']);
                if ($i === 0 && !$this->results && ($this->query !== '' || !$search)) {
                    $row->add('  Aucun résultat.', $c['dim']);
                }
                $out[] = $row->done();
                continue;
            }
            $out[] = $this->resultRow($w, $r, $idx === $this->sel);
        }
        $out[] = $this->app->footer([['key:DOWN', 'Choisir'], ['list.open', 'Ouvrir'], ['list.back', 'Retour']]);
        $cursor = $search ? [min($w - 1, 3 + mb_strlen($this->query)), 1] : null;
        return [$out, $cursor];
    }

    private function resultRow(int $w, array $r, bool $selected): string
    {
        $c = T::$c;
        [, $path, $ln, $col, $text, $mlen] = $r;
        $row = new Row($w, $selected ? $c['sel'] : $c['text']);
        $row->add(' ')->add($path, $selected ? null : $c['folder']);
        if ($ln < 0) {
            return $row->add('  (nom de la note)', $selected ? null : $c['dim'])->done();
        }
        $row->add(':' . ($ln + 1) . '  ', $selected ? null : $c['dim']);
        $lead = mb_strlen($text) - mb_strlen(ltrim($text));
        $room = $w - $row->used();
        $start = $lead;
        if ($col - $start > intdiv($room, 2)) {
            $start = max($lead, $col - intdiv($room, 3));
            $row->add('…');
        }
        $row->add(mb_substr($text, $start, $col - $start));
        $row->add(mb_substr($text, $col, $mlen), $c['match']);
        $row->add(mb_substr($text, $col + $mlen));
        return $row->done();
    }

    public function handle(string $k): void
    {
        $this->follow = true;
        $n = count($this->results);
        $page = max(1, $this->bodyH - 1);
        switch ($k) {
            case 'UP': $this->sel = max(0, $this->sel - 1); return;
            case 'DOWN': $this->sel = max(0, min($n - 1, $this->sel + 1)); return;
            case 'PGUP': $this->sel = max(0, $this->sel - $page); return;
            case 'PGDN': $this->sel = max(0, min($n - 1, $this->sel + $page)); return;
            case 'BACKSPACE':
            case 'C-BACKSPACE':
                if ($this->mode === 'search' && $this->query !== '') {
                    $this->query = $k === 'BACKSPACE' ? mb_substr($this->query, 0, -1) : '';
                    $this->search();
                }
                return;
        }
        if (($a = $this->app->keys->action('list', $k)) !== null) {
            $this->perform($a);
            return;
        }
        if ($this->mode !== 'search') {
            return;
        }
        if (strncmp($k, 'PASTE:', 6) === 0) {
            $k = strtok(substr($k, 6), "\n") ?: '';
        } elseif ($k === 'C-V') {
            $k = strtok($this->app->clip->paste(), "\n") ?: '';
        } elseif (mb_strlen($k) !== 1) {
            return;
        }
        $this->query .= $k;
        $this->search();
    }

    public function perform(string $a): void
    {
        if ($a === 'list.back') {
            $this->app->view = $this->back;
        } elseif ($a === 'list.open' && ($r = $this->results[$this->sel] ?? null)) {
            $this->app->openFromList($this->back, $r[0], max(0, $r[2]), $r[3]);
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
        if ($kind !== 'press' || $y < 2 || !isset($this->results[$idx])) {
            return;
        }
        $this->sel = $idx;
        $this->follow = true;
        if ($this->app->clicks >= 2) {
            $this->perform('list.open');
        }
    }
}

// =====================================================================
//  Notes d'exemple créées avec un nouveau coffre
// =====================================================================
final class Welcome
{
    public static function seed(Vault $v): void
    {
        $v->createNote('Bienvenue', <<<TXT
# Bienvenue dans Securetxt

Chaque note est chiffrée sur le disque : sans l'application et ton
mot de passe, le dossier du coffre ne contient que du bruit.

## Les liens entre notes
Écris un nom entre doubles crochets : j'ai envoyé le contrat par [[mail]]
Mets le curseur sur le lien et fais Ctrl+O (ou Ctrl+clic) pour ouvrir la note enfant.

  - lien vert  : la note existe
  - lien jaune : elle n'existe pas encore, Ctrl+O la crée : [[idées/Ma prochaine idée]]

Échap revient à la note d'où tu viens (la note parente).
Ctrl+B liste toutes les notes qui pointent vers la note ouverte.

## Raccourcis utiles
  Ctrl+S  enregistrer          Ctrl+F  chercher partout
  Ctrl+C / X / V  copier, couper, coller    Ctrl+Z / Y  annuler, rétablir
  Ctrl+A  tout sélectionner    Maj+flèches  sélectionner
  Ctrl+P  paramètres (thème, disposition, souris, tous les raccourcis)
  Ctrl+Q  quitter
TXT);
        $v->createNote('mail', "Bonjour monsieur,\n\nVeuillez trouver ci-joint le contrat signé.\n");
        $v->createFolder('idées');
    }
}
