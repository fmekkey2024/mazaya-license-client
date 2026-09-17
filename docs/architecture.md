# Architecture and security

How the licensing system works end to end — the two halves, the licensing
technique, and the security layers — and, just as important, what it does *not*
protect against.

## The two halves

| Part | What it is | Where it runs |
|---|---|---|
| **License Server** | The authority: issues licences, signs tokens, receives heartbeats, hosts the Filament admin panel. Owns the Ed25519 private keys. | Our server (`moodle`) |
| **License Agent** (this package, `mazaya/license-client`) | Verifies locally and enforces the verdict inside the customer's product. | The customer's on-prem server |

The governing rule: **the Agent is a verifier, never an authority.** It can only
honour what the server's private key signed. The private key never leaves the
licence server.

Two hostnames on purpose, both pointing at the same server:

- `api-license.mazaya.dev` — the API, the only thing a customer whitelists.
- `license.mazaya.dev` — the admin panel, meant to be IP-restricted.

## Technologies

- **Server:** Laravel 13 + **Filament 5** (admin panel), PHP 8.3, **MySQL 8.4**.
- **Agent:** a light Composer package; supports Laravel 10–13, PHP 8.2+, requires
  `ext-sodium`.
- **Signing:** **Ed25519** via **libsodium** (`sodium_crypto_sign_*`) — not a JWT
  library. A token is `base64url(json).base64url(sig)` (~520 bytes, verifies in
  ~0.09 ms). One Ed25519 keypair **per product**, addressed by `kid` in the
  payload; rotation retires the old key rather than deleting it, so tokens
  already on customer servers keep verifying.

## The licensing technique, end to end

```
1. Activation
   Agent ──(licence key + hardware fingerprint)──► Server
   Server verifies, creates an activation, returns:
     • install_id
     • a per-install secret   (used to sign heartbeats)
     • a signed token (Ed25519)
   Agent stores them, then seals its config and pins the signed manifest.

2. Verification  (every request, locally, no network)
   Agent decodes the token, verifies it with the public key, and checks:
     seq not lower than the last seen · clock not wound back · exp still valid
     · hardware fingerprint matches · config seal intact · Agent manifest valid

3. Renewal  (heartbeat, every ~2 minutes)
   Agent ──(HMAC-signed: install_id + fingerprint + code_ok)──► Server
   Server checks the fingerprint (strict), reads the licence state, and returns
   a verdict plus a fresh token when the current one is near expiry.
   → Renewal needs the network; verification never does.
```

Key properties:

- **`exp = min(now + TTL, ends_at)`.** A token is never signed past the paid
  subscription end date — the billing date is the truth.
- **`seq` is claimed atomically** and is monotonic: the Agent refuses any token
  whose sequence is lower than one it has already seen (anti-rollback).
- **Renew only near expiry.** The heartbeat runs every ~2 minutes for enforcement
  and reporting, but a fresh token is minted only when the current one is close
  to expiry, so a frequent heartbeat does not grow the issued-token record.

## Security layers, strongest first

| # | Layer | Prevents | Kind |
|---|---|---|---|
| 1 | **Ed25519 authenticity** | forging a token / licence | cryptographic, **absolute** (private key is server-only) |
| 2 | **`seq` anti-rollback** | replaying an old token | cryptographic |
| 3 | **Strict hardware binding** | copying onto a second server | four hashed factors (machine-id / MAC / CPU / hostname); any change stops it and flags `different_hardware` |
| 4 | **Clock-rollback guard** | extending a licence by winding the clock back | "highest timestamp ever seen" + a one-hour drift tolerance |
| 5 | **Vendor-signed manifest** | editing the Agent's own code, then self-resealing | Ed25519, signed by the vendor at build; `license:reseal` cannot adopt an edit |
| 6 | **Config seal (HMAC)** | editing `.env` (hosted bypass, server swap, key swap) | per-install HMAC (detection) |
| 7 | **Host-app strict integrity** | editing the customer's own app code | HMAC → auto-suspend → vendor approves → resume |
| 8 | **Offline leash (4h)** | pulling the network to dodge a decision | force-stops after 4h with no heartbeat |
| 9 | **`honor_server_stop`** | running on after suspend / revoke | hard lock (reads too) until reactivated from the panel |
| 10 | **Console guard** | running artisan to work around enforcement | blocks commands (with a recovery allowlist) |
| 11 | **Encrypted rotating backups** | losing the signing keys | XChaCha20 + an offline recovery key |

### The distinction that matters

Signing closes **forgery** — no one without the private key can produce a token
or manifest the Agent accepts. It does **not** close **removal** — an attacker
with root can edit the check itself to skip it, because the code is readable on
their machine. That is the bar a **bytecode encoder (ionCube / SourceGuardian)**
raises; it is a deliberate, deferred next layer. The honest positioning is:

> Strong protection for the on-prem licensing threat model, without claiming
> absolute resistance to a privileged attacker.

### On the config seal vs. the manifest

The **config seal** is a symmetric HMAC keyed by the per-install secret, which
lives on the customer's box (it also signs heartbeats). So it is genuine tamper
**detection**, but a privileged attacker who extracts the secret can recompute
it. That is exactly why the Agent's own code was moved to the **Ed25519
manifest** in v1.10.0: the customer's machine holds only the public key, so an
edit to the enforcement code can no longer be self-resealed. The manifest's
digest is produced by the vendor's build (`license:sign-agent-manifest`), never
from a digest the client sends.

## State machine

```
active ──near exp──► expiring ──past exp──► grace ──grace ends──► expired
  │
  ├─ suspend / revoke / block (from the panel) ──► stopped   (hard lock: reads + writes)
  ├─ no heartbeat for > 4h ─────────────────────► stopped   (offline)
  ├─ Agent code / seal / .env edited ───────────► tampered  (hard lock)
  └─ no licence at all ─────────────────────────► unlicensed (reads survive, read-only)
```

- `stopped` and `tampered` are **hard** — even reads are refused, regardless of
  enforcement mode.
- `stopped` (vendor) lifts **only** on a real `active` verdict from the server,
  never by replaying a heartbeat.
- `tampered` from an Agent-code edit lifts **only** by restoring the signed code
  (or a new vendor release). It is not approve-resumable — unlike a host-app
  edit, which is.

## Two kinds of code change

- **Agent code** (this package) → covered by the vendor-signed manifest → a hard
  stop, not approvable. Only the vendor's signed code runs.
- **Host-app code** (the customer's own application, under strict integrity) →
  auto-suspends, and the vendor approves it from the panel, which reseals and
  resumes the install on its next heartbeat.

## Distribution

The signed manifest must be present for an install to run, and it is signed with
the product's private key — so it is **baked into the per-product build**
(`agent-manifest.mlic`, injected after `git archive`; it is gitignored in the
source). *Fetch-at-activation* — where the server stores the approved manifest
per product + version and returns it, so one generic artifact works for every
product — is the planned next increment.

## Quality gate and versioning

- A version-controlled **security suite** (`tests/security/`) runs an adversarial
  matrix plus contract tests against a real installation. `run.sh` is the gate:
  any red suite exits non-zero and blocks the release. At v1.10.0 it is **116
  assertions** (Token 15 · Binding 25 · Availability 18 · Tamper 24 · Contract 21
  · Manifest 13), green and deterministic. `.github/workflows/security.yml` wires
  it into CI, and documents that the encoded (ionCube) build must re-pass the
  whole suite on a clean server before release.
- Current release: **v1.10.0** (client and server).
- Remaining on the roadmap: **ionCube** (code hardening, deferred) and
  **fetch-at-activation** (a single generic artifact).
