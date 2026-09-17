#!/bin/bash
# ===========================================================================
# Group — Vendor-signed manifest  (PENDING v1.10.0)
#
# The v1.9.0 seal is a symmetric HMAC keyed by the per-install secret, which
# lives on the customer's machine — so a privileged attacker can extract it,
# edit code, and recompute the seal (see matrix/d-tamper.sh, D7). v1.10.0
# replaces it with an Ed25519 manifest the VENDOR signs at build time: the
# client verifies with the public key and can no longer produce a valid seal
# for code the vendor did not sign.
#
# When that lands, this suite asserts (the forgery test is the load-bearing one):
#   vendor-signed manifest + original artifact            -> ACCEPT
#   vendor-signed manifest + modified artifact            -> REJECT (digest mismatch)
#   client-forged manifest (own secret) for modified code -> REJECT (no vendor key)
#   manifest from another installation                    -> REJECT
#   manifest from another product                         -> REJECT
#   manifest from an older agent version                  -> REJECT
#
# Until then it skips cleanly so the gate stays meaningful.
# ===========================================================================
echo "  SKIP  vendor-signed manifest suite — pending v1.10.0 (see file header)"
exit 0
