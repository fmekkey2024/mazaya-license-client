<?php

declare(strict_types=1);

namespace Mazaya\License\Guard;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Binds the licence to the configuration it was issued against.
 *
 * Without this, the cheapest bypass in the whole system is a one-word edit:
 * LICENSE_MODE=hosted in .env short-circuits every check. Sealing means the
 * settings that decide enforcement are covered by a keyed digest that only a
 * live licence can produce — change any of them and the licence stops
 * verifying, because it is no longer the licence that was issued for this
 * configuration.
 *
 * The key is the per-installation secret, which is issued by the licence
 * server and stored encrypted. An attacker who can read it can already forge
 * heartbeats, so this adds no new exposure.
 */
final class Seal
{
    public function __construct(
        private readonly Config $config,
        private readonly Integrity $integrity,
    ) {}

    public function compute(string $installId, string $secret): string
    {
        return hash_hmac('sha256', $this->canonical($installId), $secret);
    }

    public function matches(string $expected, string $installId, string $secret): bool
    {
        return hash_equals($expected, $this->compute($installId, $secret));
    }

    /**
     * Every setting that changes whether, or against whom, enforcement happens.
     *
     * Sorted and JSON-encoded so the digest depends on the values alone and not
     * on the order they happen to be read in.
     */
    private function canonical(string $installId): string
    {
        // The key values, not just their ids: swapping in an attacker's public
        // key under the same kid would otherwise leave the seal intact.
        $keys = (array) $this->config->get('license.keys', []);
        ksort($keys);

        return json_encode([
            'install'     => $installId,
            'mode'        => (string) $this->config->get('license.mode'),
            'product'     => (string) $this->config->get('license.product'),
            'server'      => rtrim((string) $this->config->get('license.server'), '/'),
            'enforcement' => (string) $this->config->get('license.enforcement'),
            'keys'        => $keys,

            // The licensing code itself. Editing a check, or deleting the file
            // it lives in, changes this and the licence stops verifying.
            'code'        => $this->integrity->digest(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
