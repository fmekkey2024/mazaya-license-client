# Security suite

Adversarial tests for the licence Agent: each one is something a person with
root on a customer's server would actually try, run against a real installation
talking to the live licence service. These are version-controlled and gate
releases — `run.sh` exits non-zero if anything fails, and no build ships on a
red suite.

> The older `/root/*.sh` scripts on the moodle host are legacy, manual,
> operational scratch. **These, in the repo, are canonical.**

## Layout

```
tests/security/
├── lib.sh                 # shared harness (assertions + client/server control)
├── run.sh                 # the gate: runs every suite, exits 1 on any failure
├── matrix/
│   ├── a-token.sh         # A — Token Security (crypto accept/reject + states)
│   ├── b-binding.sh       # B — Installation Binding (strict fingerprint)
│   ├── c-availability.sh  # C — Availability / Time (outage, clock, 4h leash, grace)
│   ├── d-tamper.sh        # D — Tamper (edit Guard/seal/config; approve→resume)
│   └── manifest.sh        # (pending v1.10.0) vendor-signed manifest + forgery test
└── contract/
    └── contract.sh        # wire schema, token claims, seq monotonicity, transitions
```

## Environment

The suite drives the **`lic-test`** integration install on the moodle host by
default (a real Laravel app + real License API + real heartbeats). Every path is
an env var in `lib.sh`, so the same suite points at another install — including a
clean server running the **encoded** build after ionCube — without edits:

| var | default |
|---|---|
| `CLIENT_APP` / `CLIENT_USER` | `/var/www/lic-test` / `lictest` |
| `SERVER_APP` / `SERVER_USER` | `/var/www/mazaya-license` / `mlicense` |
| `APP_HOST` / `API_HOST` | `test-license.mazaya.dev` / `api-license.mazaya.dev` |
| `LICENSE_ID` | `574` (the server-side licence row for this install) |

It needs root on the runner (edits `/etc/hosts` for the outage sim, runs artisan
as the app users, uses `faketime` and `mysql`), and leaves the install clean —
every suite restores a known-good baseline on exit.

## Running

```bash
sudo bash tests/security/run.sh            # the whole gate
sudo bash tests/security/matrix/d-tamper.sh # one group
```

Counts at v1.9.0: **A 15 · B 25 · C 18 · D 22 · Contract 21 = 101 assertions.**

## What each group proves

- **A — Token Security.** The Verifier accepts only what the server's Ed25519
  key signed (modified payload, flipped signature, wrong public key, unknown
  kid, malformed → all rejected) and the token-issued states track the server
  verdict (active / suspended / revoked / blocked).
- **B — Installation Binding.** Each of the four hashed factors (machine-id,
  MAC, CPU, hostname) changing is refused and flagged `different_hardware`; the
  stored print is not overwritten on a mismatch; the IP is telemetry, never a
  bind factor.
- **C — Availability / Time.** Local verification survives an outage; a
  wound-back clock is refused (with an NTP-drift tolerance); the 4h offline leash
  force-stops and recovers on the next successful heartbeat; grace/expiry behave
  (note: the 4h leash supersedes day-scale grace in the default config).
- **D — Tamper.** Editing the Guard / Verifier / Fingerprint code, removing the
  seal, or changing a sealed `.env` value all halt the app; the v1.9.0 loop
  edit → auto-suspend → vendor approve → reseal directive → resume is proven end
  to end. **D7 documents the known v1.9.0 gap** (local self-reseal adopts an edit
  with no vendor sign-off) that v1.10.0 closes.
- **Contract.** Locks the protocol shape both sides depend on — token payload
  claims (names + types), heartbeat response keys, `seq` monotonicity, verdict
  mapping, and the invariant that a stop is lifted **only** by a server verdict,
  never by a replayed heartbeat.

## CI gate

`.github/workflows/security.yml` runs `run.sh` and blocks the build on a red
suite. It needs a runner that can reach a test install (a self-hosted runner on
the moodle host, or a provisioned throwaway install). The pipeline order is:

```
source → unit/contract → adversarial matrix → (green) → build
                                                   │
                                                encode (ionCube)
                                                   │
                        install encoded build on a clean server
                                                   │
                        run.sh AGAIN on the encoded build → (green) → release
```

Re-running the suite on the **encoded** artifact is not optional: encoding can
change autoloading/reflection, so the encoded build must independently pass.

## Roadmap

- **v1.10.0 — vendor-signed manifest.** Replaces the symmetric HMAC seal (whose
  key is on the customer's box) with an Ed25519 manifest the vendor signs at
  build time. Closes the D7 self-reseal gap. `matrix/manifest.sh` already holds
  the assertions it must pass, skipping until then.
- **ionCube.** Encode the Agent's Guard, then re-run this whole suite on the
  encoded build before release.
