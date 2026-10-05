#!/usr/bin/env bash
# Run all static analysis, linters and formatter checks. --fix applies fixes first.
# Contract: https://github.com/crazy-goat/.github/blob/main/standard/lint.md
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

FIX=0
[ "${1:-}" = "--fix" ] && FIX=1
failed=()

step() {
    local name="$1"; shift
    echo "==> $name"
    "$@" || failed+=("$name")
}

# Every tracked or new (not ignored) shell script, including extensionless ones
# (bin/docker-test): a file counts when its first line is a sh/bash shebang.
shell_scripts() {
    local file first
    git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' file; do
        [ -f "$file" ] || continue
        first=""
        case "$file" in
            *.sh) printf '%s\0' "$file" ;;
            *)
                IFS= read -r first < "$file" 2>/dev/null || true
                [[ "$first" =~ ^#!.*[/\ ](ba)?sh([\ ]|$) ]] && printf '%s\0' "$file"
                ;;
        esac
    done || true
}

dockerfiles() {
    git ls-files -z --cached --others --exclude-standard | grep -zE '(^|/)Dockerfile(\.[^/]*)?$' || true
}

# A missing tool is a failure, not a skip.
need() {
    command -v "$1" >/dev/null 2>&1 || { echo "$1 is not installed" >&2; return 1; }
}
run_shellcheck() { need shellcheck && shell_scripts | xargs -0 -r shellcheck; }
run_hadolint() { need hadolint && dockerfiles | xargs -0 -r hadolint; }

if [ "$FIX" = 1 ]; then
    vendor/bin/rector process
    vendor/bin/php-cs-fixer fix -v
    php bin/kb-lint.php --fix
fi

step "composer validate" composer validate --strict
# composer audit needs the network and a new upstream advisory fails it on any branch, so
# the pre-push hook sets LINT_SKIP_AUDIT=1; CI and a plain run always audit (DEC-008).
if [ "${LINT_SKIP_AUDIT:-0}" = 1 ]; then
    echo "==> composer audit (skipped: LINT_SKIP_AUDIT=1)"
else
    step "composer audit" composer audit
fi
step "php-cs-fixer" vendor/bin/php-cs-fixer fix -v --dry-run --diff
step "phpstan" vendor/bin/phpstan analyse --no-progress
step "rector" vendor/bin/rector process --dry-run
step "kb-lint" php bin/kb-lint.php
step "changelog" php bin/check-changelog.php
step "exception-usage" php bin/check-exception-usage.php
step "upgrade-exceptions" php bin/check-upgrade-exceptions.php
step "shellcheck" run_shellcheck
step "hadolint" run_hadolint

if [ "${#failed[@]}" -gt 0 ]; then
    echo "Failed: ${failed[*]}" >&2
    exit 1
fi
echo "All checks passed."
