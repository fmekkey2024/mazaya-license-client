<?php

declare(strict_types=1);

namespace Mazaya\License\Guard;

use Illuminate\Contracts\Config\Repository as Config;
use Mazaya\License\Token\Verifier;
use Throwable;

/**
 * Verifies the Agent's own code against a manifest the VENDOR signed.
 *
 * This is what the symmetric seal could not be. The seal's key is the
 * per-installation secret, which lives on the customer's box — so a privileged
 * attacker can read it, edit a check, and recompute the seal. Worse, a single
 * `license:reseal` adopts the edit locally with no vendor involvement at all.
 *
 * The manifest closes that. It is an Ed25519-signed statement — produced by the
 * vendor's build, never by anything on the customer's server — that says "these
 * are the authentic file hashes of this Agent version". The client verifies it
 * with the public key it already trusts for tokens (so swapping that key breaks
 * token verification too) and compares the code on disk against it. An attacker
 * can edit the files, but cannot forge a manifest that vouches for the edit, and
 * `license:reseal` cannot re-sign one. The only way to change what the Agent is
 * allowed to be is a new vendor release with a new signed manifest.
 *
 * It still runs on the customer's machine, so a determined attacker can remove
 * the check itself — that is the bar ionCube raises, not this. What this removes
 * is the one-command self-reseal: forging the seal is no longer arithmetic with
 * a key you already hold.
 */
final class Manifest
{
    public const FILENAME = 'agent-manifest.mlic';

    public function __construct(
        private readonly Verifier $verifier,
        private readonly Integrity $integrity,
        private readonly Config $config,
        private readonly string $root,
    ) {}

    public function path(): string
    {
        return $this->root.DIRECTORY_SEPARATOR.self::FILENAME;
    }

    /**
     * True iff a vendor-signed manifest for this product covers the Agent code
     * on disk exactly — every file present, every hash matching, nothing added.
     */
    public function verified(): bool
    {
        $file = $this->path();

        if (! is_file($file)) {
            // A missing manifest is not "unmanaged", it is tampering: the whole
            // point is that the vendor's statement must be present to run.
            return false;
        }

        try {
            $payload = $this->verifier->verify(trim((string) file_get_contents($file)));
        } catch (Throwable) {
            return false; // bad signature, unknown key, malformed
        }

        if (($payload['kind'] ?? null) !== 'agent-manifest') {
            return false;
        }

        // A manifest signed for another product is not a manifest for this one,
        // even though the signature is genuine.
        if (($payload['product'] ?? null) !== (string) $this->config->get('license.product')) {
            return false;
        }

        $expected = $payload['files'] ?? null;

        if (! is_array($expected) || $expected === []) {
            return false;
        }

        $actual = $this->integrity->fileHashes();

        ksort($expected);
        ksort($actual);

        try {
            return hash_equals(
                json_encode($expected, JSON_THROW_ON_ERROR),
                json_encode($actual, JSON_THROW_ON_ERROR),
            );
        } catch (Throwable) {
            return false;
        }
    }
}
