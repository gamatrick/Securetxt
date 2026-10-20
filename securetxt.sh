#!/bin/sh
# ==== Securetxt ====
# Chemin de PHP 8.x (ou lance : PHP85=/chemin/vers/php ./securetxt.sh)
PHP85="${PHP85:-php}"
DIR="$(cd "$(dirname "$0")" && pwd)"
exec "$PHP85" "$DIR/securetxt.php" "$@"
