#!/bin/bash
# ===========================================================================
# The security gate. Runs every suite and fails if any one fails.
#
#   SECURITY: ALL GREEN   -> exit 0 -> build may proceed
#   SECURITY: ... FAILED  -> exit 1 -> NO RELEASE
#
# CI calls this. It runs against whatever install the lib.sh env vars point at
# (default: the lic-test integration environment on the moodle host). After
# ionCube, point it at a clean server running the *encoded* build and run it
# again — the encoding can change loading/reflection, so it must re-pass here.
# ===========================================================================
HERE="$(cd "$(dirname "$0")" && pwd)"
FAILED=0

run_one() {
  local name; name="$(basename "$1")"
  printf '\n\033[1m========== %s ==========\033[0m\n' "$name"
  if bash "$1"; then
    printf '\033[32m-- %s OK\033[0m\n' "$name"
  else
    printf '\033[31m-- %s FAILED\033[0m\n' "$name"
    FAILED=$((FAILED + 1))
  fi
}

SUITES=(
  "$HERE/matrix/a-token.sh"
  "$HERE/matrix/b-binding.sh"
  "$HERE/matrix/c-availability.sh"
  "$HERE/matrix/d-tamper.sh"
  "$HERE/contract/contract.sh"
)
# v1.10.0 onwards
[ -f "$HERE/matrix/manifest.sh" ] && SUITES+=("$HERE/matrix/manifest.sh")

for s in "${SUITES[@]}"; do run_one "$s"; done

printf '\n\033[1m===========================================\033[0m\n'
if [ "$FAILED" -eq 0 ]; then
  printf '\033[32mSECURITY: ALL SUITES GREEN\033[0m\n'
  exit 0
fi
printf '\033[31mSECURITY: %d SUITE(S) FAILED — NO RELEASE\033[0m\n' "$FAILED"
exit 1
