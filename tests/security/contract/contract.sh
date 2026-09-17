#!/bin/bash
# ===========================================================================
# Contract — the Agent<->Server protocol is a security protocol, not just JSON.
#
# Locks the shape both sides depend on, so a well-meaning rename ({"seq"} ->
# {"sequence"}) or a dropped claim is caught in CI, not in production:
#   - the signed token's payload claims (names + types)
#   - the heartbeat response schema (the exact keys the client reads)
#   - seq monotonicity (anti-rollback's foundation)
#   - error-code / verdict mapping
#   - the state-transition invariant (a stop is only lifted by the server)
# ===========================================================================
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=../lib.sh
. "$HERE/../lib.sh"

trap 'reset_clean >/dev/null 2>&1' EXIT
reset_clean >/dev/null 2>&1
cbeat

group "K1. Signed token — required claims are present and typed"
CLAIMS="$(sudo -u "$CLIENT_USER" php "$CLIENT_APP/artisan" tinker 2>/dev/null <<'PHP' | grep '^CLAIM:'
$tok = (string) app(\Mazaya\License\Storage\StateStore::class)->token();
$p   = explode('.', $tok, 2)[0];
$c   = json_decode(sodium_base642bin($p, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), true);
$req = array('v'=>'int','kid'=>'string','product'=>'string','lic_id'=>'string','customer'=>'string','install_id'=>'string','fp'=>'array','iat'=>'int','exp'=>'int','seq'=>'int','grace_days'=>'int');
foreach ($req as $k => $type) {
    $ok = array_key_exists($k, $c) && (($type==='int'&&is_int($c[$k])) || ($type==='string'&&is_string($c[$k])) || ($type==='array'&&is_array($c[$k])));
    echo "CLAIM:$k:".($ok?'ok':'BAD')."\n";
}
echo "CLAIM:fp_has_4:".((isset($c['fp'])&&count($c['fp'])===4)?'ok':'BAD')."\n";
PHP
)"
cl(){ echo "$CLAIMS" | grep -q "^CLAIM:$1:ok\$" && echo ok || echo BAD; }
for k in v kid product lic_id customer install_id fp iat exp seq grace_days fp_has_4; do
  ck "claim $k present & typed" "$(cl "$k")" "ok"
done

group "K2. Heartbeat response — the keys the client contracts on"
RESP="$(sudo -u "$CLIENT_USER" php "$CLIENT_APP/artisan" tinker 2>/dev/null <<'PHP' | grep '^RESP:'
$r = app(\Mazaya\License\LicenseManager::class)->refresh();
echo "RESP:status:".(isset($r['status'])&&is_string($r['status'])?'ok':'BAD')."\n";
echo "RESP:check_in:".(isset($r['check_in'])&&is_int($r['check_in'])?'ok':'BAD')."\n";
echo "RESP:status_value:".($r['status'] ?? 'MISSING')."\n";
PHP
)"
rk(){ echo "$RESP" | grep -q "^RESP:$1:$2\$" && echo "$2" || echo NO; }
ck "response has string 'status'"    "$(rk status ok)"    "ok"
ck "response has int 'check_in'"      "$(rk check_in ok)"  "ok"
ck "active heartbeat status=active"   "$(echo "$RESP" | sed -n 's/^RESP:status_value://p')" "active"

group "K3. seq is strictly monotonic (anti-rollback foundation)"
srv_age_tokens; cbeat; S1="$(cfield seq)"
srv_age_tokens; cbeat; S2="$(cfield seq)"
ck "two re-mints advance seq ($S1 -> $S2)" "$([ "$S2" -gt "$S1" ] && echo yes || echo no)" "yes"

group "K4. Verdict / error-code mapping is stable"
srv_set_status suspended; MS="$(sudo -u "$CLIENT_USER" php "$CLIENT_APP/artisan" tinker 2>/dev/null <<'PHP' | grep '^MAP:'
$r = app(\Mazaya\License\LicenseManager::class)->refresh();
echo "MAP:".($r['status'] ?? 'MISSING')."\n";
PHP
)"
ck "suspended licence -> status 'suspended'" "$(echo "$MS" | sed -n 's/^MAP://p')" "suspended"
srv_reset_clean; cbeat
ck "reactivated       -> status 'active'"    "$(cfield state)" "active"

group "K5. State-transition invariant — a stop lifts ONLY by a server verdict"
srv_set_status suspended; cbeat
ck "stopped after suspend"                 "$(cstate_fresh)" "stopped"
# a plain heartbeat while still suspended must NOT resurrect it
cbeat
ck "plain heartbeat cannot flip stopped->active" "$(cstate_fresh)" "stopped"
srv_set_status active; cbeat
ck "only the server active verdict lifts it" "$(cstate_fresh)" "active"

summary
