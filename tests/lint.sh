#!/usr/bin/env bash
# PHP lint of the module and tests, plus the offline tests, for every PHP binary given (WISECP needs PHP 8.1+):
#   integrations/wisecp/tests/lint.sh php8.1 php8.2 php8.3 php8.4
set -euo pipefail
cd "$(dirname "$0")/.."
[ $# -gt 0 ] || set -- php
for php in "$@"; do
    echo "== $("$php" -r 'echo PHP_VERSION;')"
    find src tests -name '*.php' -print0 | xargs -0 -n1 "$php" -l | grep -v '^No syntax errors' || true
    "$php" -d error_reporting=-1 tests/run.php | tail -1
done
