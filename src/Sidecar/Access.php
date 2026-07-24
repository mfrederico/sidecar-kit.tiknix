<?php
/**
 * Sidecar\Access — owner/team scoping + identity re-checks against CORE, shared by
 * every sidecar plugin. Reproduces the exact ownership model core's Workbench uses
 * (lib/TaskAccessControl) in raw read-only SQL over core's db. A member may only
 * ever see an instance they OWN or that is shared with one of their teams. A
 * slug/URL from the client is a lookup hint, never an authorization input.
 *
 * Generalization of the original ExplorerAccess (feature key is now a parameter).
 */

namespace app\Sidecar;

class Access {

    public function __construct(private \PDO $core) {}

    /** Team ids the member belongs to (sequential-keyed, IN()-binding safe). */
    public function teamIds(int $memberId): array {
        $st = $this->core->prepare('SELECT team_id FROM teammember WHERE member_id = ?');
        $st->execute([$memberId]);
        return array_values(array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** Instance ids shared (m2m) with any of the member's teams. */
    public function sharedInstanceIds(int $memberId): array {
        $teamIds = $this->teamIds($memberId);
        if (!$teamIds || !$this->tableExists('instance_team')) return [];
        $ph = implode(',', array_fill(0, count($teamIds), '?'));
        $st = $this->core->prepare("SELECT DISTINCT instance_id FROM instance_team WHERE team_id IN ($ph)");
        $st->execute(array_values($teamIds));
        return array_values(array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** Instance ids the member OWNS ∪ shared-with-team — the allowed set. */
    public function accessibleInstanceIds(int $memberId): array {
        $owned = [];
        if ($this->tableExists('instance')) {
            $st = $this->core->prepare('SELECT id FROM instance WHERE member_id = ?');
            $st->execute([$memberId]);
            $owned = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
        }
        return array_values(array_unique(array_merge($owned, $this->sharedInstanceIds($memberId))));
    }

    /** True iff the member owns the instance or it is shared with one of their teams. */
    public function canAccess(int $memberId, int $instanceId): bool {
        return $instanceId > 0 && in_array($instanceId, $this->accessibleInstanceIds($memberId), true);
    }

    /** Accessible instances as [{id, slug, app, name, owned}] for a picker. */
    public function instances(int $memberId): array {
        $ids = $this->accessibleInstanceIds($memberId);
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->core->prepare("SELECT id, slug, app, display_name, member_id FROM instance WHERE id IN ($ph) ORDER BY slug");
        $st->execute(array_values($ids));
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'slug' => (string) $r['slug'], 'app' => (string) ($r['app'] ?? ''),
                'name' => (string) ($r['display_name'] ?? $r['slug']), 'owned' => (int) $r['member_id'] === $memberId,
            ];
        }
        return $out;
    }

    /**
     * Resolve a client URL/host/slug to an instance the member may access, or null
     * (→ 403). Validated slug → instance bean → ownership check. The caller builds
     * any filesystem path from the returned slug/app, never from client input.
     */
    public function resolveInstance(string $urlOrSlug, int $memberId): ?array {
        $slug = $this->slugFromInput($urlOrSlug);
        if ($slug === null || !$this->tableExists('instance')) return null;
        $st = $this->core->prepare('SELECT id, slug, app, display_name, member_id FROM instance WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r) return null;                                          // unknown slug → 403
        if (!$this->canAccess($memberId, (int) $r['id'])) return null; // un-owned → 403
        return ['id' => (int) $r['id'], 'slug' => (string) $r['slug'], 'app' => (string) ($r['app'] ?? ''),
                'name' => (string) ($r['display_name'] ?? $r['slug'])];
    }

    /** Extract a validated slug from a URL/host/bare slug, or null. Never a path. */
    public function slugFromInput(string $in): ?string {
        $in = trim($in);
        if ($in === '' || $in === '/') return null;
        if (preg_match('#^https?://#i', $in) || strpos($in, '.') !== false || strpos($in, '/') !== false) {
            $host = parse_url(preg_match('#^https?://#i', $in) ? $in : "https://{$in}", PHP_URL_HOST) ?: '';
            $in = explode('.', $host)[0] ?? '';
        }
        $in = strtolower($in);
        return preg_match('/^[a-z0-9]([a-z0-9-]{0,48}[a-z0-9])?$/', $in) ? $in : null;
    }

    /** Re-check at SSO consume: member exists + active. Returns row or null. */
    public function memberIfActive(int $memberId): ?array {
        $st = $this->core->prepare('SELECT id, level, status, email FROM member WHERE id = ? LIMIT 1');
        $st->execute([$memberId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r) return null;
        $status = strtolower((string) ($r['status'] ?? 'active'));
        if ($status !== '' && !in_array($status, ['active', 'enabled', '1'], true)) return null;
        return ['id' => (int) $r['id'], 'level' => (int) $r['level'], 'email' => (string) ($r['email'] ?? '')];
    }

    /** Re-check a feature grant against core's settings table. */
    public function featureEnabled(int $memberId, string $feature): bool {
        $st = $this->core->prepare("SELECT setting_value FROM settings WHERE member_id = ? AND setting_key = ? LIMIT 1");
        $st->execute([$memberId, 'feature.' . $feature]);
        return (string) $st->fetchColumn() === '1';
    }

    private function tableExists(string $t): bool {
        $st = $this->core->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1");
        $st->execute([$t]);
        return (bool) $st->fetchColumn();
    }
}
