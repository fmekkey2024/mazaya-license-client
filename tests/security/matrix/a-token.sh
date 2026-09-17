#!/bin/bash
# ===========================================================================
# Group A — Token Security
#
# The token is an Ed25519-signed claim from the licence server. The client is a
# verifier, never an authority: it accepts only what the server's private key
# signed, and only ever moving forward (anti-rollback). This group feeds the
# real Verifier forged and mutated tokens, and drives the token-issued states
# through the live server.
# ===========================================================================
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=../lib.sh
. "$HERE/../lib.sh"

trap 'reset_clean >/dev/null 2>&1' EXIT

reset_clean >/dev/null 2>&1

# --- cryptographic acceptance/rejection, straight at the Verifier ----------
group "A1. Verifier — accepts only what the server's key signed"
PROBE="$(sudo -u "$CLIENT_USER" php "$CLIENT_APP/artisan" tinker 2>/dev/null <<'PHP' | grep '^PROBE:'
$keys = (array) config('license.keys');
$tok  = (string) app(\Mazaya\License\Storage\StateStore::class)->token();
$v    = new \Mazaya\License\Token\Verifier($keys);
$parts = explode('.', $tok, 2);
$p = $parts[0]; $s = isset($parts[1]) ? $parts[1] : '';
$flipP = substr($p, 0, -1) . ($p[strlen($p)-1] === 'A' ? 'B' : 'A');
$flipS = substr($s, 0, -1) . ($s[strlen($s)-1] === 'A' ? 'B' : 'A');
try { $v->verify($tok);              echo "PROBE:valid:accept\n"; }      catch (\Throwable $e) { echo "PROBE:valid:reject\n"; }
try { $v->verify($flipP.'.'.$s);     echo "PROBE:modpayload:accept\n"; } catch (\Throwable $e) { echo "PROBE:modpayload:reject\n"; }
try { $v->verify($p.'.'.$flipS);     echo "PROBE:badsig:accept\n"; }     catch (\Throwable $e) { echo "PROBE:badsig:reject\n"; }
try { $v->verify('not-a-token');     echo "PROBE:malformed:accept\n"; }  catch (\Throwable $e) { echo "PROBE:malformed:reject\n"; }
$kid   = json_decode(sodium_base642bin($p, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), true)['kid'] ?? 'x';
$bogus = sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
$vw    = new \Mazaya\License\Token\Verifier(array($kid => $bogus));
try { $vw->verify($tok);             echo "PROBE:wrongkey:accept\n"; }   catch (\Throwable $e) { echo "PROBE:wrongkey:reject\n"; }
$vu    = new \Mazaya\License\Token\Verifier(array());
try { $vu->verify($tok);             echo "PROBE:unknownkid:accept\n"; } catch (\Throwable $e) { echo "PROBE:unknownkid:reject\n"; }
PHP
)"
pv(){ echo "$PROBE" | grep -q "^PROBE:$1:$2\$" && echo "$2" || echo "MISSING"; }
ck "valid token            -> accept" "$(pv valid accept)"      "accept"
ck "modified payload       -> reject" "$(pv modpayload reject)" "reject"
ck "flipped signature      -> reject" "$(pv badsig reject)"     "reject"
ck "malformed token        -> reject" "$(pv malformed reject)"  "reject"
ck "wrong public key       -> reject" "$(pv wrongkey reject)"   "reject"
ck "unknown kid            -> reject" "$(pv unknownkid reject)" "reject"

# --- token-driven states through the live server ---------------------------
group "A2. Token-issued states (driven by the server verdict)"
cbeat
ck "valid licence  -> active"        "$(cstate_fresh)" "active"
srv_set_status suspended; cbeat
ck "suspended      -> stopped"       "$(cstate_fresh)" "stopped"
srv_set_status revoked;   cbeat
ck "revoked        -> stopped"       "$(cstate_fresh)" "stopped"
srv_set_status active;    cbeat
ck "reactivated    -> active"        "$(cstate_fresh)" "active"
srv_block;                cbeat
ck "activation blocked -> stopped"   "$(cstate_fresh)" "stopped"
srv_unblock;              cbeat
ck "unblocked      -> active"        "$(cstate_fresh)" "active"

# --- anti-rollback: a verdict never travels backwards without the server ---
group "A3. Anti-rollback (seq) — the vendor's decision cannot be replayed away"
# Prove BLOCKED -> ACTIVE is NOT reachable by replaying an old good state:
# suspend, then a plain heartbeat while the server is still suspended must NOT
# flip the client back to active (only a real server 'active' verdict does).
srv_set_status suspended; cbeat
ck "suspended before replay"          "$(cstate_fresh)" "stopped"
cbeat                                  # a second heartbeat, server still suspended
ck "replayed heartbeat stays stopped" "$(cstate_fresh)" "stopped"
srv_set_status active; cbeat
ck "only a server active verdict lifts it" "$(cstate_fresh)" "active"

summary
