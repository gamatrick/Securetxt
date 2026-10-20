<?php

namespace Securetxt;

use RuntimeException;

/** Import de fichiers texte dans le coffre, export des notes en fichiers .txt. */
final class Transfer
{
    /** Extensions retirées du nom de la note à l'import. */
    private const STRIP_EXT = ['txt', 'md', 'markdown', 'text'];
    /** Fichiers pris en compte quand on importe un dossier entier. */
    private const DIR_EXT = ['txt', 'md', 'markdown', 'text', 'log', 'csv', 'ini', 'cfg', 'conf', 'json', 'xml',
        'yml', 'yaml', 'html', 'htm', 'sql', 'php', 'js', 'css', 'sh', 'bat', 'ps1', 'nfo'];
    private const MAX_BYTES = 10 * 1024 * 1024;

    public static function homeDir(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $home = (string) getenv('USERPROFILE');
            $docs = $home . '\\Documents';
            return (is_dir($docs) ? $docs : $home) . '\\';
        }
        return rtrim((string) getenv('HOME'), '/') . '/';
    }

    /** Nettoie un chemin tapé ou glissé dans la fenêtre (guillemets, ~). */
    public static function cleanPath(string $p): string
    {
        $p = trim($p);
        if (strlen($p) >= 2 && ($p[0] === '"' || $p[0] === "'") && substr($p, -1) === $p[0]) {
            $p = substr($p, 1, -1);
        }
        if ($p === '~' || strncmp($p, '~/', 2) === 0) {
            $p = rtrim(self::homeDir(), '/\\') . substr($p, 1);
        }
        return $p;
    }

    /** Complétion de chemin pour la touche Tab. @return list<string> */
    public static function complete(string $value): array
    {
        $value = self::cleanPath($value);
        $win = PHP_OS_FAMILY === 'Windows';
        $cut = max(strrpos($value, '/') === false ? -1 : strrpos($value, '/'), $win && strrpos($value, '\\') !== false ? strrpos($value, '\\') : -1);
        $dir = $cut >= 0 ? substr($value, 0, $cut + 1) : '';
        $prefix = $cut >= 0 ? substr($value, $cut + 1) : $value;
        $list = @scandir($dir === '' ? '.' : $dir);
        if (!$list) {
            return [];
        }
        $out = [];
        foreach ($list as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if ($prefix !== '' && ($win ? stripos($name, $prefix) : strpos($name, $prefix)) !== 0) {
                continue;
            }
            if ($prefix === '' && $name[0] === '.') {
                continue;
            }
            $out[] = $dir . $name . (is_dir($dir . $name) ? DIRECTORY_SEPARATOR : '');
            if (count($out) >= 300) {
                break;
            }
        }
        natcasesort($out);
        return array_values($out);
    }

    /**
     * Importe un fichier ou un dossier (récursif) dans le dossier $folder du coffre.
     * @return array{0:int,1:int,2:?string} [notes importées, fichiers ignorés, chemin de la première note]
     */
    public static function import(Vault $v, string $src, string $folder): array
    {
        if ($src !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]?$#', $src)) {
            $src = rtrim($src, '/\\');
        }
        if (!file_exists($src)) {
            throw new RuntimeException('Introuvable : ' . $src);
        }
        $made = 0;
        $skipped = 0;
        $first = null;
        $v->beginBatch();
        try {
            if (is_file($src)) {
                $p = self::importFile($v, $src, self::join($folder, self::noteName(basename($src))));
                $p === null ? $skipped++ : $made++;
                $first = $p;
            } else {
                $root = self::join($folder, self::safeNote(basename($src)));
                $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
                    fn ($f) => $f->getFilename()[0] !== '.'
                ));
                $files = [];
                foreach ($it as $f) {
                    if ($f->isFile()) {
                        $files[] = $f->getPathname();
                    }
                }
                natcasesort($files);
                foreach ($files as $file) {
                    if (!in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::DIR_EXT, true)) {
                        $skipped++;
                        continue;
                    }
                    $rel = preg_split('#[/\\\\]#', substr($file, strlen($src) + 1));
                    $name = self::noteName(array_pop($rel));
                    $path = $root;
                    foreach ($rel as $seg) {
                        $path .= '/' . self::safeNote($seg);
                    }
                    $p = self::importFile($v, $file, $path . '/' . $name);
                    if ($p === null) {
                        $skipped++;
                    } else {
                        $made++;
                        $first ??= $p;
                    }
                }
                if ($made === 0) {
                    $v->createFolder($root);
                }
            }
        } finally {
            $v->endBatch();
        }
        return [$made, $skipped, $first];
    }

    private static function join(string $folder, string $name): string
    {
        return $folder === '' ? $name : $folder . '/' . $name;
    }

    private static function safeNote(string $s): string
    {
        $s = trim(strtr($s, ['[' => '(', ']' => ')', '|' => '-']));
        $s = preg_replace('/[\x00-\x1F]/', '', $s);
        return $s === '' ? 'sans nom' : $s;
    }

    private static function noteName(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, self::STRIP_EXT, true)) {
            $file = substr($file, 0, -strlen($ext) - 1);
        }
        return self::safeNote($file);
    }

    private static function importFile(Vault $v, string $file, string $path): ?string
    {
        if (@filesize($file) > self::MAX_BYTES) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $text = self::decode($raw);
        if ($text === null) {
            return null;
        }
        $path = $v->uniquePath(Vault::normalize($path));
        if ($path === '' || Vault::validate($path) !== null) {
            return null;
        }
        $v->createNote($path, $text);
        return $path;
    }

    /** Texte en UTF-8 avec des fins de ligne "\n", ou null si ce n'est pas du texte. */
    private static function decode(string $raw): ?string
    {
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        } elseif (strncmp($raw, "\xFF\xFE", 2) === 0) {
            $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        } elseif (strncmp($raw, "\xFE\xFF", 2) === 0) {
            $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }
        if (strpos($raw, "\0") !== false) {
            return null;
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        return str_replace(["\r\n", "\r"], "\n", $raw);
    }

    /** Exporte des notes en .txt. $base = préfixe de dossier à retirer des chemins. */
    public static function export(Vault $v, array $ids, string $base, string $dest): int
    {
        if (!is_dir($dest) && !@mkdir($dest, 0777, true)) {
            throw new RuntimeException('Impossible de créer le dossier : ' . $dest);
        }
        $n = 0;
        foreach ($ids as $id) {
            $path = $v->path($id);
            if ($path === null) {
                continue;
            }
            $rel = $base === '' ? $path : substr($path, strlen($base) + 1);
            $parts = array_map([self::class, 'safeFile'], explode('/', $rel));
            $file = rtrim($dest, '/\\') . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
            $dir = dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
                throw new RuntimeException('Impossible de créer le dossier : ' . $dir);
            }
            $target = $file . '.txt';
            for ($i = 2; file_exists($target); $i++) {
                $target = $file . ' (' . $i . ').txt';
            }
            $content = $v->read($id);
            if (PHP_OS_FAMILY === 'Windows') {
                $content = str_replace("\n", "\r\n", $content);
            }
            if (@file_put_contents($target, $content) === false) {
                throw new RuntimeException('Écriture impossible : ' . $target);
            }
            $n++;
        }
        return $n;
    }

    /** Nom de fichier valide sous Windows comme sous Linux. */
    private static function safeFile(string $s): string
    {
        $s = rtrim(preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/', '_', $s), '. ');
        if ($s === '') {
            return '_';
        }
        if (preg_match('/^(CON|PRN|AUX|NUL|COM\d|LPT\d)(\..*)?$/i', $s)) {
            $s = '_' . $s;
        }
        return $s;
    }
}
