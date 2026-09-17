#!/bin/bash
# ===========================================================================
# Group B — Installation Binding (strict hardware fingerprint)
#
# The bind is the four hashed factors machine-id / MAC / CPU / hostname. Any
# one of them changing is a copy onto different hardware: refused, and flagged
# in the panel as different_hardware. The IP is telemetry, never a bind factor.
#
# Each factor is exercised by mutating the *stored* copy server-side, so the
# client keeps reporting its real hardware and the two no longer agree — the
# same shape as the client being moved to another machine.
# ===========================================================================
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=../lib.sh
. "$HERE/../lib.sh"

WRONG="sha256:00000000000000000000000000000000000000000000000000000000deadbeef"

trap 'reset_clean >/dev/null 2>&1' EXIT

group "B0. BASELINE — matching hardware"
reset_clean
ck "state active"                 "$(cstate)"                 "active"
ck "no different_hardware flag"    "$(srv_flag different_hardware)" "no"
ck "IP recorded (telemetry)"       "$([ -n "$(srv_last_ip)" ] && echo yes || echo no)" "yes"

# one factor at a time
for comp in machine mac cpu host; do
  group "B: changed ${comp} -> refused + flagged (copy on other hardware)"
  ORIG="$(srv_fp_get "$comp")"
  srv_fp_set "$comp" "$WRONG"
  cbeat
  ck "client stopped (token refused)"  "$(cstate)"                      "stopped"
  ck "panel flagged different_hardware" "$(srv_flag different_hardware)" "yes"
  ck "stored fingerprint NOT overwritten" "$(srv_fp_get "$comp")"        "$WRONG"
  # restore the real hardware value + clear the flag, as the panel's "accept" would
  srv_fp_set "$comp" "$ORIG"; srv_clear_flags
  cbeat
  ck "restored -> active"              "$(cstate)"                      "active"
done

group "B: multiple factors changed -> refused"
OM="$(srv_fp_get mac)"; OC="$(srv_fp_get cpu)"
srv_fp_set mac "$WRONG"; srv_fp_set cpu "$WRONG"
cbeat
ck "two factors differ -> stopped"   "$(cstate)"                      "stopped"
ck "flagged different_hardware"       "$(srv_flag different_hardware)" "yes"
srv_fp_set mac "$OM"; srv_fp_set cpu "$OC"; srv_clear_flags
cbeat
ck "restored -> active"               "$(cstate)"                      "active"

group "B: IP is telemetry, NOT a bind factor"
# All four factors match; the verdict is active regardless of the IP, which is
# only recorded. (The structural proof that strictMatch never inspects the IP
# is asserted in the contract suite.)
cbeat
ck "matching fingerprint -> active"  "$(cstate)"                      "active"
ck "last_ip still populated"          "$([ -n "$(srv_last_ip)" ] && echo yes || echo no)" "yes"
ck "no hardware flag from IP alone"   "$(srv_flag different_hardware)" "no"

summary
