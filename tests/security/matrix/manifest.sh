#!/bin/bash
# ===========================================================================
# Group — Vendor-signed Agent manifest  (v1.10.0)
#
# The Agent's own code is vouched for by an Ed25519 manifest the VENDOR signed,
# verified with the public key the client already trusts for tokens. This is the
# layer that closes the v1.9.0 self-reseal gap: an edit to the enforcement code
# can no longer be adopted locally, because no one on the customer's box can
# forge a manifest that vouches for it.
#
# The load-bearing assertion is M5: a manifest whose hashes MATCH the edited
# code but is signed with a key the client does not trust is still refused.
# ===========================================================================
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=../lib.sh
. "$HERE/../lib.sh"

VENDOR="$CLIENT_APP/vendor/mazaya/license-client"
OTHER_PRODUCT="${OTHER_PRODUCT:-hrms}"      # any product whose key the client does NOT hold
INJECTED="$VENDOR/src/__Injected.php"

trap 'rm -f "$INJECTED"; reset_clean >/dev/null 2>&1' EXIT
reset_clean

group "M0. BASELINE — the vendor-signed manifest verifies"
ck "state active"        "$(cstate)"                                   "active"
ck "manifest present"    "$([ -f "$CLIENT_MANIFEST" ] && echo yes || echo no)" "yes"

group "M1. Edit Agent code -> tampered, and NOT self-resealable"
edit_file "$GUARD_DIR/Gate.php"
ck "edit -> tampered"               "$(cstate_fresh)" "tampered"
cart license:reseal >/dev/null 2>&1; cflush
ck "self-reseal does NOT resume it" "$(cstate)"       "tampered"
restore_file "$GUARD_DIR/Gate.php"; creseal; cflush
ck "restoring vendor code -> active" "$(cstate)"      "active"

group "M2. Corrupted manifest signature -> tampered"
cp "$CLIENT_MANIFEST" /tmp/m.bak
python3 - "$CLIENT_MANIFEST" <<'PY'
import sys;p=sys.argv[1];s=open(p).read().strip()
open(p,'w').write(s[:-2]+('AA' if s[-2:]!='AA' else 'BB'))
PY
chown "$CLIENT_USER:$CLIENT_USER" "$CLIENT_MANIFEST"; cflush
ck "bad signature -> tampered"      "$(cstate)" "tampered"
cp /tmp/m.bak "$CLIENT_MANIFEST"; chown "$CLIENT_USER:$CLIENT_USER" "$CLIENT_MANIFEST"; rm -f /tmp/m.bak; cflush
ck "restore -> active"              "$(cstate)" "active"

group "M3. Missing manifest -> tampered"
mv "$CLIENT_MANIFEST" /tmp/m.bak; cflush
ck "no manifest -> tampered"        "$(cstate)" "tampered"
mv /tmp/m.bak "$CLIENT_MANIFEST"; chown "$CLIENT_USER:$CLIENT_USER" "$CLIENT_MANIFEST"; cflush
ck "restore -> active"              "$(cstate)" "active"

group "M4. Injected extra Agent file -> tampered (file-set mismatch)"
echo "<?php // injected $(date +%s)" > "$INJECTED"; chown "$CLIENT_USER:$CLIENT_USER" "$INJECTED"; cflush
ck "extra file -> tampered"         "$(cstate)" "tampered"
rm -f "$INJECTED"; cflush
ck "remove -> active"               "$(cstate)" "active"

group "M5. FORGERY — manifest matching edited code, signed with an untrusted key -> tampered"
edit_file "$GUARD_DIR/Gate.php"                       # attacker edits the enforcement code
sign_manifest_for "$OTHER_PRODUCT" "$VENDOR" "$CLIENT_MANIFEST" >/dev/null   # signs OVER the edited files, wrong key
chown "$CLIENT_USER:$CLIENT_USER" "$CLIENT_MANIFEST"; cflush
ck "correct hashes + untrusted key -> tampered" "$(cstate)" "tampered"
restore_file "$GUARD_DIR/Gate.php"; manifest_restore; creseal; cflush
ck "restore code + genuine manifest -> active"  "$(cstate)" "active"

summary
