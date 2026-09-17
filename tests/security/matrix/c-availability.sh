#!/bin/bash
# ===========================================================================
# Group C — Availability / Time
#
# The licence must survive a network outage (the whole point of local
# verification), refuse a wound-back clock, honour grace, and — new in v1.9.0 —
# force-stop once no heartbeat has reached the server for the offline leash
# (4h), recovering only when one succeeds.
# ===========================================================================
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=../lib.sh
. "$HERE/../lib.sh"

trap 'reset_clean >/dev/null 2>&1' EXIT

group "C0. BASELINE"
reset_clean
ck "state active" "$(cstate)" "active"

group "C1. Network outage — local verification survives"
net_break
OUT="$(cart license:heartbeat)"
ck "heartbeat reports unreachable, no crash" "$(echo "$OUT" | grep -qiE 'reach|unreadable|could not' && echo yes || echo no)" "yes"
ck "scheduler --quiet-fail exits 0" "$(cart license:heartbeat --quiet-fail >/dev/null 2>&1; echo $?)" "0"
ck "system keeps working offline"   "$(cstate_fresh)" "active"
ck "writes still allowed offline"   "$(chttp /save POST)" "200"
net_restore

group "C2. Clock rollback"
read -r ST _ _ <<< "$(cfstate '-1y')";  ck "one year back  -> invalid"          "$ST" "invalid"
read -r ST _ _ <<< "$(cfstate '-30m')"; ck "30 min back    -> tolerated (drift)" "$ST" "active"
read -r ST _ _ <<< "$(cfstate '-2h')";  ck "2 hours back   -> beyond tolerance"  "$ST" "invalid"

group "C3. Offline leash (max_offline_hours = 4)"
# A real recent heartbeat is the baseline; faketime moves 'now' forward so the
# last heartbeat ages past (or not) the leash, with the token still valid.
cbeat
read -r ST WR _ <<< "$(cfstate '+3h')"; ck "3h offline  -> still active"        "$ST" "active"
read -r ST WR _ <<< "$(cfstate '+5h')"; ck "5h offline  -> force-stopped"       "$ST" "stopped"
read -r ST WR _ <<< "$(cfstate '+5h')"; ck "  writes blocked while stopped"     "$WR" "False"
ck "recovers when a heartbeat gets through" "$(cbeat; cstate_fresh)" "active"

group "C4. Grace and expiry (token clock, leash raised so it doesn't mask them)"
# NOTE: in the default config the 4h leash supersedes the day-scale grace/expiry
# — anything faketime moves past 4h is 'stopped' (offline) first. That is
# correct v1.9.0 behaviour. To exercise the token-clock grace/expiry LOGIC we
# raise the leash for this block only.
HAD_LEASH="$(grep '^LICENSE_MAX_OFFLINE_HOURS=' "$CLIENT_APP/.env" || true)"
cenv_set LICENSE_MAX_OFFLINE_HOURS 1000000
srv_set_grace 7; srv_age_tokens; cbeat     # force a re-mint so the new token carries grace_days=7, TTL ~5d
read -r ST WR _ <<< "$(cfstate '+6d')";  ck "past expiry, inside grace -> grace" "$ST" "grace"
read -r ST WR _ <<< "$(cfstate '+6d')";  ck "  still usable in grace"            "$WR" "True"
read -r ST WR _ <<< "$(cfstate '+13d')"; ck "past grace -> expired"              "$ST" "expired"
read -r ST WR _ <<< "$(cfstate '+13d')"; ck "  writes blocked once expired"      "$WR" "False"
srv_set_grace 0; cbeat                     # restore
if [ -n "$HAD_LEASH" ]; then cenv_set LICENSE_MAX_OFFLINE_HOURS "${HAD_LEASH#*=}"; else cenv_unset LICENSE_MAX_OFFLINE_HOURS; fi

group "C5. Recovery after the server returns"
ck "state active again" "$(cstate_fresh)" "active"
ck "writes allowed"     "$(chttp /save POST)" "200"

summary
