<?php
/**
 * Sidecar\Token — signed single-use SSO handoff token shared by ALL sidecar plugins.
 *
 * Core mints a short-lived token proving {member_id, level, email, feature grant};
 * a sidecar (explorer / store / any future plugin) verifies it and establishes its
 * own session. Each sidecar is a SEPARATE app with its own app_key, so the
 * signature uses a per-plugin DEDICATED shared secret (core `[sidecar.<name>]
 * sso_secret`, mirrored into the plugin's `[sidecar] sso_secret`).
 *
 * Envelope: b64url(json(claims)) . "." . hex(HMAC-SHA256(payload, secret)). Claims
 * carry iat/exp/nonce/aud; `aud` = the plugin name, so a token minted for one
 * plugin can never be replayed against another. The nonce is burned single-use by
 * the verifier (see Sidecar\Sso::consume).
 *
 * This is the generalization of the original ExplorerToken; it lands in CORE so
 * every sidecar clone inherits it verbatim.
 */

namespace app\Sidecar;

class Token {

    /** Default handoff lifetime (seconds) — tiny; it's a redirect hop. */
    public const TTL = 120;

    /** Mint a signed token; iat/exp/nonce/aud added here. Returns "payload.sig". */
    public static function mint(array $claims, string $secret, string $aud, int $ttl = self::TTL): string {
        $claims['aud']   = $aud;
        $claims['iat']   = time();
        $claims['exp']   = time() + max(1, $ttl);
        $claims['nonce'] = bin2hex(random_bytes(16));
        $payload = self::b64url((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
        return $payload . '.' . hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Verify a token against $secret and the expected audience. Returns the claims
     * on success, null on any failure. Constant-time compare. Does NOT check the
     * nonce — the caller burns it single-use so replay protection stays stateful.
     */
    public static function verify(string $token, string $secret, string $expectedAud): ?array {
        if ($secret === '') return null;
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;
        [$payload, $sig] = $parts;
        if (!hash_equals(hash_hmac('sha256', $payload, $secret), $sig)) return null;
        $json   = self::b64urlDecode($payload);
        $claims = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($claims)) return null;
        if (empty($claims['exp']) || time() > (int) $claims['exp']) return null;
        if (($claims['aud'] ?? null) !== $expectedAud) return null;
        if (empty($claims['nonce'])) return null;   // replay protection; caller checks member_id etc.
        return $claims;
    }

    private static function b64url(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
    private static function b64urlDecode(string $s) {
        return base64_decode(strtr($s, '-_', '+/'));
    }
}
