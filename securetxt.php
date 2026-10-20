<?php
/**
 * Securetxt — notes chiffrées en mode texte.
 *
 *   php securetxt.php                  ouvre le coffre "coffre" à côté de ce fichier
 *   php securetxt.php D:\mes-notes     ouvre (ou crée) un autre coffre
 *   php securetxt.php --raccourci      crée un raccourci sur le Bureau
 */

if (PHP_VERSION_ID < 80000) {
    fwrite(STDERR, "Securetxt demande PHP 8.0 ou plus (version actuelle : " . PHP_VERSION . ").\n");
    exit(1);
}
$missing = [];
foreach (['sodium', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}
if (PHP_OS_FAMILY === 'Windows' && !extension_loaded('ffi')) {
    $missing[] = 'ffi';
}
if ($missing) {
    fwrite(STDERR, 'Extensions PHP manquantes : ' . implode(', ', $missing)
        . "\nActive-les dans le php.ini de cette version de PHP (extension=...).\n");
    exit(1);
}

mb_internal_encoding('UTF-8');
foreach (['Text', 'Theme', 'Keys', 'Settings', 'Terminal', 'Clipboard', 'Screen', 'Vault', 'Transfer',
          'Shortcut', 'App', 'Views', 'Editor', 'SettingsView'] as $f) {
    require __DIR__ . '/src/' . $f . '.php';
}

$arg = $argv[1] ?? null;
if ($arg === '--raccourci') {
    echo Securetxt\Shortcut::create(__DIR__), "\n";
    exit(0);
}

$dir = $arg ?? __DIR__ . DIRECTORY_SEPARATOR . 'coffre';
$term = PHP_OS_FAMILY === 'Windows' ? new Securetxt\WindowsTerminal() : new Securetxt\PosixTerminal();
register_shutdown_function([$term, 'stop']);

try {
    (new Securetxt\App(new Securetxt\Vault($dir), $term, __DIR__))->run();
} catch (Throwable $e) {
    $term->stop();
    fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
