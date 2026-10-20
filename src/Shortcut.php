<?php

namespace Securetxt;

/** Crée un raccourci vers l'application (Bureau + menu Démarrer / menu des applications). */
final class Shortcut
{
    public static function create(string $appDir): string
    {
        $appDir = rtrim($appDir, '/\\');
        return PHP_OS_FAMILY === 'Windows' ? self::windows($appDir) : self::linux($appDir);
    }

    private static function windows(string $dir): string
    {
        $q = fn (string $s): string => "'" . str_replace("'", "''", $s) . "'";
        $bat = $dir . '\\securetxt.bat';
        $ico = $dir . '\\securetxt.ico';
        $ps = '$ErrorActionPreference = "Stop"' . "\n"
            . '$app = ' . $q($dir) . "\n"
            . '$bat = ' . $q($bat) . "\n"
            . '$ico = ' . $q($ico) . "\n"
            . <<<'PS'
$ws = New-Object -ComObject WScript.Shell
$wt = Get-Command wt.exe -ErrorAction SilentlyContinue
foreach ($place in @([Environment]::GetFolderPath('Desktop'), [Environment]::GetFolderPath('Programs'))) {
    if (-not $place) { continue }
    $lnk = $ws.CreateShortcut((Join-Path $place 'Securetxt.lnk'))
    if ($wt) {
        $lnk.TargetPath = $wt.Source
        $lnk.Arguments = '-d "' + $app + '" --title Securetxt cmd /c "' + $bat + '"'
    } else {
        $lnk.TargetPath = $bat
        $lnk.Arguments = ''
    }
    $lnk.WorkingDirectory = $app
    if (Test-Path $ico) { $lnk.IconLocation = $ico + ',0' }
    $lnk.Description = 'Securetxt - notes chiffrees'
    $lnk.Save()
    Write-Output ('OK ' + $lnk.FullName)
}
PS;
        $enc = base64_encode(mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8'));
        $out = (string) shell_exec('powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . $enc . ' 2>&1');
        $ok = substr_count($out, 'OK ');
        if ($ok > 0) {
            return 'Raccourci « Securetxt » créé sur le Bureau' . ($ok > 1 ? ' et dans le menu Démarrer.' : '.');
        }
        return 'Échec de la création du raccourci : ' . trim(preg_replace('/\s+/', ' ', $out));
    }

    private static function linux(string $dir): string
    {
        $esc = fn (string $s): string => '"' . addcslashes($s, '"`$\\') . '"';
        $entry = "[Desktop Entry]\nType=Application\nName=Securetxt\nComment=Notes chiffrées\n"
            . 'Exec=sh ' . $esc($dir . '/securetxt.sh') . "\n"
            . 'Path=' . $dir . "\n"
            . 'Icon=' . $dir . "/securetxt.png\nTerminal=true\nCategories=Utility;TextEditor;\n";
        $home = rtrim((string) getenv('HOME'), '/');
        $places = [$home . '/.local/share/applications'];
        $desk = trim((string) shell_exec('xdg-user-dir DESKTOP 2>/dev/null'));
        if ($desk === '' || $desk === $home) {
            $desk = is_dir($home . '/Bureau') ? $home . '/Bureau' : $home . '/Desktop';
        }
        if (is_dir($desk)) {
            $places[] = $desk;
        }
        $done = [];
        foreach ($places as $p) {
            if (!is_dir($p)) {
                @mkdir($p, 0755, true);
            }
            $f = $p . '/securetxt.desktop';
            if (@file_put_contents($f, $entry) !== false) {
                @chmod($f, 0755);
                $done[] = $f;
            }
        }
        return $done ? 'Raccourci créé : ' . implode(' et ', $done) : 'Impossible de créer le raccourci.';
    }
}
