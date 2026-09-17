#!/bin/bash
# ---------------------------------------------------------------------------
# Shared harness for the security suites.
#
# These tests are adversarial: each one is a thing someone with root on a
# customer's server would actually try, run against a real installation talking
# to the live licence service. The scripts live in git; the environment they run
# against is the `lic-test` integration install on the moodle host.
#
# Every knob is an env var with a lic-test default, so the same suite can point
# at another install (or an encoded build on a clean server) without editing.
# ---------------------------------------------------------------------------

set -u

CLIENT_APP="${CLIENT_APP:-/var/www/lic-test}"
CLIENT_USER="${CLIENT_USER:-lictest}"
SERVER_APP="${SERVER_APP:-/var/www/mazaya-license}"
SERVER_USER="${SERVER_USER:-mlicense}"
APP_HOST="${APP_HOST:-test-license.mazaya.dev}"
API_HOST="${API_HOST:-api-license.mazaya.dev}"
LICENSE_ID="${LICENSE_ID:-574}"          # the server-side licence row for this install
HOSTS_FILE="${HOSTS_FILE:-/etc/hosts}"

G=$'\033[32m'; R=$'\033[31m'; Y=$'\033[33m'; D=$'\033[2m'; O=$'\033[0m'
P=0; F=0

# ---- assertions -----------------------------------------------------------
ck() {  # ck "label" actual expected
  if [ "$2" = "$3" ]; then
    P=$((P + 1)); printf "  ${G}PASS${O}  %-54s ${D}%s${O}\n" "$1" "$2"
  else
    F=$((F + 1)); printf "  ${R}FAIL${O}  %-54s got [%s] want [%s]\n" "$1" "$2" "$3"
  fi
}
group() { printf "\n${Y}%s${O}\n" "$1"; }
summary() {
  printf "\n  ${G}%d passed${O}%s\n" "$P" "$([ "$F" -gt 0 ] && printf "   ${R}%d failed${O}" "$F")"
  [ "$F" -eq 0 ]
}

# ---- client-side control --------------------------------------------------
cart()   { sudo -u "$CLIENT_USER" php "$CLIENT_APP/artisan" "$@" 2>&1; }
cfake()  { local t="$1"; shift; sudo -u "$CLIENT_USER" faketime -f "$t" php "$CLIENT_APP/artisan" "$@" 2>&1; }
creseal(){ cart license:reseal >/dev/null 2>&1; }
cbeat()  { cart license:heartbeat >/dev/null 2>&1; }
cflush() { cart optimize:clear >/dev/null 2>&1; }            # drops the 60s state cache AND any cached config (.env edits)
cjson()  { cart license:status --json 2>/dev/null; }
cstate() { cjson | python3 -c 'import json,sys;print(json.load(sys.stdin)["state"])' 2>/dev/null || echo ERROR; }
# Force a fresh evaluation: the Gate caches its verdict for license.cache_ttl
# (60s) in a store shared across processes, so a read straight after a mutation
# would otherwise return the pre-mutation verdict.
cstate_fresh() { cflush; cstate; }
cfield() { cjson | python3 -c "import json,sys;print(json.load(sys.stdin).get('$1'))" 2>/dev/null || echo ERROR; }
# faketime status → "state allows_writes allows_reads"
cfstate(){ cfake "$1" license:status --json 2>/dev/null | python3 -c 'import json,sys;d=json.load(sys.stdin);print(d["state"],d["allows_writes"],d["allows_reads"])' 2>/dev/null || echo "ERROR ? ?"; }

# HTTP read/write enforcement against the installed app (through FPM, so opcache matters).
chttp()  { curl -sk -o /dev/null -w '%{http_code}' -X "${2:-GET}" -H "Host: $APP_HOST" "https://127.0.0.1$1"; }
# after editing a covered file, FPM needs revalidate_freq (~2s) to see it
settle() { sleep "${1:-3}"; }

# ---- server-side control (the ONLY legitimate way a verdict may change) ----
stink() { sudo -u "$SERVER_USER" php "$SERVER_APP/artisan" tinker --execute="$1" 2>&1 | grep -viE 'psy|deprecat|^[[:space:]]*$'; }

srv_status()      { stink "echo \App\Models\License::find($LICENSE_ID)->status->value;" | tail -1; }
srv_set_status()  { stink "\App\Models\License::find($LICENSE_ID)->update(['status'=>\App\Enums\LicenseStatus::from('$1')]);" >/dev/null; }
srv_activation()  { stink "\$a=\App\Models\License::find($LICENSE_ID)->activations()->first();echo \$a->status->value.'|'.var_export(\$a->reseal_requested,true).'|'.json_encode(\$a->integrity_flags);" | tail -1; }
srv_block()       { stink "\App\Models\License::find($LICENSE_ID)->activations()->first()->update(['status'=>\App\Enums\ActivationStatus::Blocked]);" >/dev/null; }
srv_unblock()     { stink "\App\Models\License::find($LICENSE_ID)->activations()->first()->update(['status'=>\App\Enums\ActivationStatus::Active]);" >/dev/null; }
# emulate the panel's "Approve code & resume"
srv_approve_code(){ stink "\$l=\App\Models\License::find($LICENSE_ID);\$a=\$l->activations()->first();\$l->update(['status'=>\App\Enums\LicenseStatus::Active,'auto_suspended'=>false,'suspend_reason'=>null]);\$f=\$a->integrity_flags??[];unset(\$f['code_modified']);\$a->update(['reseal_requested'=>true,'integrity_flags'=>\$f?:null]);" >/dev/null; }
# hard reset the server row to a clean active state
srv_reset_clean() { stink "\$l=\App\Models\License::find($LICENSE_ID);\$a=\$l->activations()->first();\$l->update(['status'=>\App\Enums\LicenseStatus::Active,'auto_suspended'=>false,'suspend_reason'=>null]);\$a->update(['status'=>\App\Enums\ActivationStatus::Active,'reseal_requested'=>false,'integrity_flags'=>null]);" >/dev/null; }

# ---- stored fingerprint (server side) — for binding tests -------------------
srv_fp_get()   { stink "echo \App\Models\License::find($LICENSE_ID)->activations()->first()->fingerprint['$1'] ?? '';" | tail -1; }
srv_fp_set()   { stink "\$a=\App\Models\License::find($LICENSE_ID)->activations()->first();\$fp=\$a->fingerprint;\$fp['$1']='$2';\$a->update(['fingerprint'=>\$fp]);" >/dev/null; }
srv_flag()     { stink "echo isset(\App\Models\License::find($LICENSE_ID)->activations()->first()->integrity_flags['$1'])?'yes':'no';" | tail -1; }
srv_clear_flags(){ stink "\App\Models\License::find($LICENSE_ID)->activations()->first()->update(['integrity_flags'=>null]);" >/dev/null; }
srv_last_ip()  { stink "echo \App\Models\License::find($LICENSE_ID)->activations()->first()->last_ip ?? '';" | tail -1; }
srv_set_grace(){ stink "\App\Models\License::find($LICENSE_ID)->update(['grace_days'=>$1]);" >/dev/null; }
# age every token for this install so the next heartbeat re-mints (v1.9.0 only
# renews near expiry); used to force a fresh token that carries new claims
srv_age_tokens(){ stink "\$a=\App\Models\License::find($LICENSE_ID)->activations()->first();\App\Models\IssuedToken::where('activation_id',\$a->id)->update(['expires_at'=>now()->addHour()]);" >/dev/null; }

# ---- client .env (non-sealed knobs only) -----------------------------------
cenv_set()   { local k="$1" v="$2" f="$CLIENT_APP/.env"; if grep -q "^$k=" "$f"; then sed -i "s|^$k=.*|$k=$v|" "$f"; else echo "$k=$v" >> "$f"; fi; cflush; }
cenv_unset() { sed -i "/^$1=/d" "$CLIENT_APP/.env"; cflush; }

# ---- network outage simulation (RFC5737 black-hole, never 127.0.0.1) -------
net_break()   { cp "$HOSTS_FILE" "$HOSTS_FILE.sec-bak"; echo "192.0.2.1 $API_HOST" >> "$HOSTS_FILE"; }
net_restore() { [ -f "$HOSTS_FILE.sec-bak" ] && mv "$HOSTS_FILE.sec-bak" "$HOSTS_FILE"; }

# ---- covered-file editing (restored from git, the vendor source of truth) --
CLIENT_PKG="${CLIENT_PKG:-/var/www/packages/mazaya-license-client}"
edit_file()    { echo "// sec-test $(date +%s%N)" >> "$1"; chown "$CLIENT_USER:$CLIENT_USER" "$1"; }
restore_file() { local rel="${1#$CLIENT_APP/vendor/mazaya/license-client/}"; cp "$CLIENT_PKG/$rel" "$1"; chown "$CLIENT_USER:$CLIENT_USER" "$1"; }
GUARD_DIR="$CLIENT_APP/vendor/mazaya/license-client/src/Guard"
FP_DIR="$CLIENT_APP/vendor/mazaya/license-client/src/Fingerprint"

# ---- bring the whole system back to a known-good baseline ------------------
reset_clean() {
  # restore any edited covered files from the vendor source
  for f in "$GUARD_DIR"/Gate.php "$GUARD_DIR"/Seal.php "$GUARD_DIR"/Integrity.php \
           "$CLIENT_APP/vendor/mazaya/license-client/src/Token/Verifier.php" \
           "$FP_DIR"/Collector.php; do
    [ -f "$f" ] && restore_file "$f"
  done
  net_restore
  srv_reset_clean
  srv_unblock
  creseal
  cflush
  cbeat
  cflush
}
