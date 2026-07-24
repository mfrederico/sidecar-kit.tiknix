<?php
/**
 * Sidecar\Registry — core's directory of installed sidecar plugins.
 *
 * Each plugin is one `[sidecar.<name>]` section in core's conf/config.ini:
 *   [sidecar.explorer]
 *   url        = https://explorer.tiknix.com
 *   sso_secret = <shared with the plugin's [sidecar] sso_secret>
 *   feature    = explorer          ; the Feature flag gating who may launch it
 *   label      = Architecture Explorer
 *   icon       = bi-diagram-3      ; optional nav icon
 *
 * Core's generic launcher (controls/Sidecar::launch/<name>) reads this to gate +
 * mint a handoff token, so adding a plugin is config, not code.
 */

namespace app\Sidecar;

class Registry {

    /** All registered plugins: name => [url, sso_secret, feature, label, icon]. */
    public static function all(): array {
        $ini = @parse_ini_file(dirname(__DIR__, 2) . '/conf/config.ini', true) ?: [];
        $out = [];
        foreach ($ini as $section => $vals) {
            if (strncmp($section, 'sidecar.', 8) !== 0 || !is_array($vals)) continue;
            $name = substr($section, 8);
            if ($name === '') continue;
            $out[$name] = [
                'name'       => $name,
                'url'        => rtrim((string) ($vals['url'] ?? ''), '/'),
                'sso_secret' => (string) ($vals['sso_secret'] ?? ''),
                'feature'    => (string) ($vals['feature'] ?? $name),
                'label'      => (string) ($vals['label'] ?? ucfirst($name)),
                'icon'       => (string) ($vals['icon'] ?? 'bi-box'),
            ];
        }
        return $out;
    }

    public static function get(string $name): ?array {
        return self::all()[$name] ?? null;
    }

    /** Registered + configured (has url + secret) — safe to show a launch link. */
    public static function launchable(): array {
        return array_filter(self::all(), fn($p) => $p['url'] !== '' && $p['sso_secret'] !== '');
    }

    /**
     * Mint a handoff token for $name from a member array {id, level, email}. Returns
     * the launch URL, or null if the plugin is unknown/unconfigured. The CALLER must
     * have already gated on the feature flag.
     */
    public static function launchUrl(string $name, array $member): ?string {
        $p = self::get($name);
        if (!$p || $p['url'] === '' || $p['sso_secret'] === '') return null;
        $token = Token::mint([
            'member_id' => (int) $member['id'],
            'level'     => (int) $member['level'],
            'email'     => (string) ($member['email'] ?? ''),
            'feature'   => $p['feature'],
        ], $p['sso_secret'], $name);
        return $p['url'] . '/sso/consume?token=' . urlencode($token);
    }
}
