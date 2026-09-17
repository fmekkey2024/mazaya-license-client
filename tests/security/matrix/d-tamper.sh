#!/bin/bash
# ===========================================================================
# Group D — Tamper
#
# Everything someone with root on the customer's box would edit to defeat the
# Agent, plus the v1.9.0 vendor-approval loop (edit -> auto-suspend -> approve
# -> reseal directive -> resume). Each scenario restores the previous state.
#
#   ./d-tamper.sh
# ===========================================================================
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=../lib.sh
. "$HERE/../lib.sh"

DB="${DB:-lictest_db}"
LIC_FILE="$CLIENT_APP/storage/app/license/license.lic"

trap 'reset_clean >/dev/null 2>&1' EXIT

group "D0. BASELINE — licensed, sealed, serving"
reset_clean
ck "state active"        "$(cstate)"          "active"
ck "GET  /report 200"    "$(chttp /report)"   "200"
ck "POST /save 200"      "$(chttp /save POST)" "200"

group "D1. Guard modified (Gate.php) — detected by the seal"
edit_file "$GUARD_DIR/Gate.php"
ck "CLI state tampered"  "$(cstate_fresh)"    "tampered"
settle; cflush
ck "reads halt (lock)"   "$(chttp /report)"   "402"
ck "writes halt"         "$(chttp /save POST)" "402"
restore_file "$GUARD_DIR/Gate.php"; creseal; cflush
ck "reseal restores"     "$(cstate)"          "active"

group "D2. Verifier modified — detected"
edit_file "$CLIENT_APP/vendor/mazaya/license-client/src/Token/Verifier.php"
ck "state tampered"      "$(cstate_fresh)"    "tampered"
restore_file "$CLIENT_APP/vendor/mazaya/license-client/src/Token/Verifier.php"; creseal; cflush
ck "restored"            "$(cstate)"          "active"

group "D3. Fingerprint collector modified — detected"
edit_file "$FP_DIR/Collector.php"
ck "state tampered"      "$(cstate_fresh)"    "tampered"
restore_file "$FP_DIR/Collector.php"; creseal; cflush
ck "restored"            "$(cstate)"          "active"

group "D4. Seal removed (DB row + licence file)"
mysql -e "UPDATE ${DB}.license_state SET seal=NULL;" 2>/dev/null
python3 - "$LIC_FILE" <<'PY'
import json,os,sys
p=sys.argv[1]
if os.path.exists(p):
    d=json.load(open(p)); d.pop('seal',None); json.dump(d,open(p,'w'))
PY
cflush
ck "no seal is tampering" "$(cstate)"         "tampered"
cart license:reseal >/dev/null 2>&1; cflush
ck "reseal restores it"   "$(cstate)"         "active"

group "D5. Sealed .env value changed (LICENSE_SERVER -> attacker)"
ORIG_SRV="$(grep '^LICENSE_SERVER=' "$CLIENT_APP/.env")"
sed -i 's|^LICENSE_SERVER=.*|LICENSE_SERVER=https://evil.example|' "$CLIENT_APP/.env"; cflush
ck "config change detected" "$(cstate)"       "tampered"
sed -i "s|^LICENSE_SERVER=.*|$ORIG_SRV|" "$CLIENT_APP/.env"; cflush
ck "restored"             "$(cstate)"         "active"

group "D6. Code edit -> auto-suspend -> vendor approve -> resume (v1.9.0)"
edit_file "$GUARD_DIR/Gate.php"
ck "client sees tampered"       "$(cstate_fresh)"                 "tampered"
cbeat
ck "server auto-suspended"      "$(srv_status)"                   "suspended"
srv_approve_code
ck "server active, reseal set"  "$(srv_activation | cut -d'|' -f1-2)" "active|true"
cbeat                                   # directive reseals the client, no manual reseal
ck "client resumed to active"   "$(cstate)"                       "active"
cbeat                                   # confirms code_ok, server clears the directive
ck "server cleared reseal_req"  "$(srv_activation | cut -d'|' -f2)" "false"
# clean up the edit we asked the vendor to accept
restore_file "$GUARD_DIR/Gate.php"; creseal; cbeat; cflush
ck "back to clean baseline"     "$(cstate)"                       "active"

group "D7. KNOWN GAP (v1.9.0) — local self-reseal adopts an edit with no vendor sign-off"
# Documented, not a pass/fail on security: this is exactly what the v1.10.0
# vendor-signed manifest closes. Today, anyone who can run the command adopts
# their own edit. The manifest suite (added with v1.10.0) will flip this to a
# FAIL-if-accepted assertion.
edit_file "$GUARD_DIR/Gate.php"
BEFORE="$(cstate_fresh)"
cart license:reseal >/dev/null 2>&1; cflush
AFTER="$(cstate)"
printf "  ${Y}NOTE${O}  self-reseal: tampered->%s (edit adopted locally, no vendor sign-off)\n" "$AFTER"
ck "gap present as expected (edit->tampered)" "$BEFORE" "tampered"
restore_file "$GUARD_DIR/Gate.php"; creseal; cflush

summary
