<?php

namespace Securetxt;

use RuntimeException;

/**
 * Coffre chiffré.
 *
 * Sur le disque :
 *   vault.meta        paramètres publics (sel, coût Argon2) + bloc de vérification
 *   index.ltx         arborescence chiffrée (noms des notes et des dossiers)
 *   notes/<id>.ltx    contenu chiffré de chaque note (le nom du fichier ne révèle rien)
 *
 * Chiffrement : clé dérivée du mot de passe avec Argon2id (sodium_crypto_pwhash),
 * puis XSalsa20-Poly1305 (sodium_crypto_secretbox) avec un nonce aléatoire par écriture.
 */
final class Vault
{
    private const MAGIC = 'LTX1';
    private const CHECK = 'LTX-VAULT-OK';
    public const LINK_RE = '/\[\[([^\[\]\r\n]+)\]\]/u';

    private ?string $key = null;
    /** @var array<string,string> id => chemin ("dossier/sous-dossier/nom") */
    private array $notes = [];
    /** @var array<string,bool> dossiers créés explicitement (même vides) */
    private array $folders = [];
    /** @var array<string,string> contenu déchiffré, gardé en mémoire */
    private array $cache = [];
    /** @var array<string,string> contenu "replié" (minuscules sans accents) pour la recherche */
    private array $folded = [];
    private ?array $byPath = null;
    private int $version = 0;
    /** Augmente à chaque écriture de note (contenu modifié). */
    private int $contentVersion = 0;
    private bool $batch = false;
    private bool $pendingIndex = false;

    public function __construct(private string $dir)
    {
    }

    public function dir(): string { return $this->dir; }
    public function exists(): bool { return is_file($this->dir . '/vault.meta'); }
    public function version(): int { return $this->version; }
    public function contentVersion(): int { return $this->contentVersion; }

    // ------------------------clé----------------------------------------

    public function create(string $password): void
    {
        if (!is_dir($this->dir . '/notes') && !@mkdir($this->dir . '/notes', 0700, true)) {
            throw new RuntimeException('Impossible de créer le dossier du coffre : ' . $this->dir);
        }
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
        $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
        $this->key = $this->derive($password, $salt, $ops, $mem);
        $meta = [
            'format' => 'ltx-vault',
            'v' => 1,
            'kdf' => 'argon2id13',
            'salt' => base64_encode($salt),
            'ops' => $ops,
            'mem' => $mem,
            'check' => base64_encode($this->seal(self::CHECK)),
        ];
        $this->writeAtomic($this->dir . '/vault.meta', json_encode($meta, JSON_PRETTY_PRINT));
        $this->notes = [];
        $this->folders = [];
        $this->saveIndex();
    }

    public function unlock(string $password): bool
    {
        $meta = json_decode((string) @file_get_contents($this->dir . '/vault.meta'), true);
        if (!is_array($meta) || ($meta['format'] ?? '') !== 'ltx-vault') {
            throw new RuntimeException('Fichier vault.meta illisible.');
        }
        $this->key = $this->derive($password, base64_decode($meta['salt']), (int) $meta['ops'], (int) $meta['mem']);
        if ($this->open(base64_decode($meta['check'])) !== self::CHECK) {
            $this->lock();
            return false;
        }
        $this->loadIndex();
        return true;
    }

    public function lock(): void
    {
        if ($this->key !== null) {
            sodium_memzero($this->key);
        }
        $this->key = null;
        $this->notes = $this->folders = $this->cache = $this->folded = [];
        $this->touch();
    }

    private function derive(string $pw, string $salt, int $ops, int $mem): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $pw, $salt, $ops, $mem,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }

    private function seal(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::MAGIC . $nonce . sodium_crypto_secretbox($plain, $nonce, $this->key);
    }

    private function open(string $blob): ?string
    {
        if ($this->key === null || strncmp($blob, self::MAGIC, 4) !== 0) {
            return null;
        }
        $nonce = substr($blob, 4, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($blob, 4 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        return $plain === false ? null : $plain;
    }

    private function writeAtomic(string $path, string $data): void
    {
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $data, LOCK_EX) === false) {
            throw new RuntimeException('Écriture impossible : ' . $path);
        }
        if (!@rename($tmp, $path)) {
            @unlink($path);
            if (!@rename($tmp, $path)) {
                throw new RuntimeException('Écriture impossible : ' . $path);
            }
        }
    }

    // -----------------------index-----------------------------------------

    private function loadIndex(): void
    {
        $file = $this->dir . '/index.ltx';
        $this->notes = $this->folders = [];
        if (is_file($file)) {
            $json = $this->open((string) file_get_contents($file));
            $data = $json === null ? null : json_decode($json, true);
            if (!is_array($data)) {
                throw new RuntimeException('Index du coffre corrompu.');
            }
            $this->notes = $data['notes'] ?? [];
            foreach ($data['folders'] ?? [] as $f) {
                $this->folders[$f] = true;
            }
        }
        $this->touch();
    }

    /** Pendant un import massif, l'index n'est écrit qu'une fois à la fin. */
    public function beginBatch(): void
    {
        $this->batch = true;
    }

    public function endBatch(): void
    {
        $this->batch = false;
        if ($this->pendingIndex) {
            $this->pendingIndex = false;
            $this->saveIndex();
        }
    }

    private function saveIndex(): void
    {
        if ($this->batch) {
            $this->pendingIndex = true;
            return;
        }
        $data = ['v' => 1, 'notes' => (object) $this->notes, 'folders' => $this->explicitFolders()];
        $this->writeAtomic($this->dir . '/index.ltx', $this->seal(json_encode($data, JSON_UNESCAPED_UNICODE)));
        $this->touch();
    }

    private function touch(): void
    {
        $this->version++;
        $this->byPath = null;
    }

    private function noteFile(string $id): string
    {
        return $this->dir . '/notes/' . $id . '.ltx';
    }

    // ----------------------------chemins------------------------------------

    public static function normalize(string $p): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $p)) as $seg) {
            $seg = trim($seg);
            if ($seg === '') {
                continue;
            }
            if ($seg === '.' || $seg === '..') {
                return '';
            }
            $parts[] = $seg;
        }
        return implode('/', $parts);
    }

    public static function validate(string $path): ?string
    {
        if ($path === '') {
            return 'Nom invalide.';
        }
        if (preg_match('/[\[\]|\x00-\x1F]/u', $path)) {
            return 'Les caractères [ ] | sont interdits dans un nom.';
        }
        if (mb_strlen($path) > 200) {
            return 'Nom trop long.';
        }
        return null;
    }

    public static function parent(string $p): string
    {
        $i = strrpos($p, '/');
        return $i === false ? '' : substr($p, 0, $i);
    }

    public static function base(string $p): string
    {
        $i = strrpos($p, '/');
        return $i === false ? $p : substr($p, $i + 1);
    }

    // ---------------------------lecture-------------------------------------

    /** @return array<string,string> */
    public function notes(): array { return $this->notes; }

    /** @return list<string> */
    public function explicitFolders(): array
    {
        return array_map('strval', array_keys($this->folders));
    }

    public function path(string $id): ?string { return $this->notes[$id] ?? null; }

    public function findByPath(string $path): ?string
    {
        if ($this->byPath === null) {
            $this->byPath = [];
            foreach ($this->notes as $id => $p) {
                $this->byPath[Text::fold($p)] = $id;
            }
        }
        return $this->byPath[Text::fold($path)] ?? null;
    }

    /**
     * Résout la cible d'un lien [[cible]] écrit dans une note du dossier $from :
     * d'abord dans le même dossier, puis depuis la racine, puis (si la cible n'a pas
     * de "/") n'importe quelle note portant ce nom, la moins profonde en premier.
     */
    public function resolveLink(string $target, string $from): ?string
    {
        $t = self::normalize($target);
        if ($t === '') {
            return null;
        }
        if ($from !== '' && ($id = $this->findByPath($from . '/' . $t)) !== null) {
            return $id;
        }
        if (($id = $this->findByPath($t)) !== null) {
            return $id;
        }
        if (strpos($t, '/') !== false) {
            return null;
        }
        $ft = Text::fold($t);
        $best = null;
        $bestPath = null;
        foreach ($this->notes as $id => $p) {
            if (Text::fold(self::base($p)) !== $ft) {
                continue;
            }
            if ($bestPath === null
                || substr_count($p, '/') < substr_count($bestPath, '/')
                || (substr_count($p, '/') === substr_count($bestPath, '/') && Text::cmp($p, $bestPath) < 0)) {
                $best = $id;
                $bestPath = $p;
            }
        }
        return $best;
    }

    public function read(string $id): string
    {
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }
        $file = $this->noteFile($id);
        if (!is_file($file)) {
            return $this->cache[$id] = '';
        }
        $plain = $this->open((string) file_get_contents($file));
        if ($plain === null) {
            throw new RuntimeException('Note illisible (fichier abîmé) : ' . ($this->notes[$id] ?? $id));
        }
        return $this->cache[$id] = $plain;
    }

    public function folded(string $id): string
    {
        return $this->folded[$id] ??= Text::fold($this->read($id));
    }

    // --------------------------------écriture--------------------------------

    public function write(string $id, string $content): void
    {
        $this->contentVersion++;
        $this->writeAtomic($this->noteFile($id), $this->seal($content));
        $this->cache[$id] = $content;
        unset($this->folded[$id]);
    }

    public function createNote(string $path, string $content = ''): string
    {
        if ($this->findByPath($path) !== null) {
            throw new RuntimeException('Une note porte déjà ce nom : ' . $path);
        }
        do {
            $id = 'n' . bin2hex(random_bytes(8));
        } while (isset($this->notes[$id]));
        $this->write($id, $content);
        $this->notes[$id] = $path;
        if ($this->batch && $this->byPath !== null) {
            $this->byPath[Text::fold($path)] = $id;
        }
        $this->saveIndex();
        return $id;
    }

    /** "nom", ou "nom (2)", "nom (3)"... si le nom est déjà pris. */
    public function uniquePath(string $path): string
    {
        if ($this->findByPath($path) === null) {
            return $path;
        }
        for ($i = 2; ; $i++) {
            if ($this->findByPath($path . ' (' . $i . ')') === null) {
                return $path . ' (' . $i . ')';
            }
        }
    }

    public function createFolder(string $path): void
    {
        $this->folders[$path] = true;
        $this->saveIndex();
    }

    public function rename(string $id, string $newPath): void
    {
        $other = $this->findByPath($newPath);
        if ($other !== null && $other !== $id) {
            throw new RuntimeException('Une note porte déjà ce nom : ' . $newPath);
        }
        $this->notes[$id] = $newPath;
        $this->saveIndex();
    }

    public function renameFolder(string $old, string $new): void
    {
        $prefix = $old . '/';
        $moves = [];
        foreach ($this->notes as $id => $p) {
            if (strncmp($p, $prefix, strlen($prefix)) === 0) {
                $np = $new . '/' . substr($p, strlen($prefix));
                $other = $this->findByPath($np);
                if ($other !== null && $other !== $id) {
                    throw new RuntimeException('Conflit : ' . $np . ' existe déjà.');
                }
                $moves[$id] = $np;
            }
        }
        foreach ($moves as $id => $np) {
            $this->notes[$id] = $np;
        }
        $folders = [];
        foreach ($this->explicitFolders() as $f) {
            if ($f === $old) {
                $f = $new;
            } elseif (strncmp($f, $prefix, strlen($prefix)) === 0) {
                $f = $new . '/' . substr($f, strlen($prefix));
            }
            $folders[$f] = true;
        }
        $folders[$new] = true;
        $this->folders = $folders;
        $this->saveIndex();
    }

    /** @return list<string> */
    public function notesInFolder(string $folder): array
    {
        $prefix = $folder . '/';
        $ids = [];
        foreach ($this->notes as $id => $p) {
            if (strncmp($p, $prefix, strlen($prefix)) === 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public function deleteNote(string $id): void
    {
        @unlink($this->noteFile($id));
        unset($this->notes[$id], $this->cache[$id], $this->folded[$id]);
        $this->saveIndex();
    }

    public function deleteFolder(string $folder): void
    {
        foreach ($this->notesInFolder($folder) as $id) {
            @unlink($this->noteFile($id));
            unset($this->notes[$id], $this->cache[$id], $this->folded[$id]);
        }
        $prefix = $folder . '/';
        foreach ($this->explicitFolders() as $f) {
            if ($f === $folder || strncmp($f, $prefix, strlen($prefix)) === 0) {
                unset($this->folders[$f]);
            }
        }
        $this->saveIndex();
    }
}
