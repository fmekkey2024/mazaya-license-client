<?php

declare(strict_types=1);

namespace Mazaya\License\Token;

use Mazaya\License\Exceptions\InvalidLicense;

/**
 * Verifies a license token against the public keys baked into this install.
 *
 * Entirely offline by design. The network is needed to *renew* a license, never
 * to *check* one — which is what lets a customer behind a broken VPN keep
 * working for weeks while still expiring exactly on schedule.
 */
final class Verifier
{
    /** @param array<string, string> $keys kid => base64url public key */
    public function __construct(private readonly array $keys) {}

    /**
     * @return array<string, mixed> the decoded payload
     * @throws InvalidLicense
     */
    public function verify(string $token): array
    {
        if (substr_count($token, '.') !== 1) {
            throw InvalidLicense::because('malformed');
        }

        [$encodedPayload, $encodedSignature] = explode('.', $token, 2);

        try {
            $json      = $this->decode($encodedPayload);
            $signature = $this->decode($encodedSignature);
            $payload   = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw InvalidLicense::because('undecodable');
        }

        if (! is_array($payload) || ! isset($payload['kid'])) {
            throw InvalidLicense::because('no_kid');
        }

        $publicKey = $this->keys[$payload['kid']] ?? null;

        if ($publicKey === null) {
            // Almost always a key rotation the customer has not been given the
            // new public key for yet, not an attack.
            throw InvalidLicense::because('unknown_kid');
        }

        if (! sodium_crypto_sign_verify_detached($signature, $json, $this->decode($publicKey))) {
            throw InvalidLicense::because('signature');
        }

        return $payload;
    }

    private function decode(string $value): string
    {
        return sodium_base642bin($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }
}
