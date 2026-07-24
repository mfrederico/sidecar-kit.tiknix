<?php
/**
 * Sidecar\Sso — the shared identity boundary for every sidecar plugin.
 *
 * A plugin's controls/Sso.php extends this. consume() is the ONLY way a member gets
 * a session: it verifies the signed handoff token core minted (aud = the plugin
 * name), burns the nonce single-use (replay → reject), re-checks against core's db
 * that the member is still active AND still has the plugin's feature grant (so a
 * revoke on core propagates), regenerates the session id, and stores only
 * {member_id, level, email} under $_SESSION[<plugin>]. Config drives everything:
 * [sidecar] name / feature / sso_secret / landing.
 */

namespace app\Sidecar;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Bean;

class Sso extends Control {

    /** GET /sso/consume?token=… — validate the handoff and establish the session. */
    public function consume($params = []) {
        $name    = Kernel::name();
        $feature = (string) (Flight::get('sidecar.feature') ?? $name);
        $secret  = (string) (Flight::get('sidecar.sso_secret') ?? '');
        $landing = (string) (Flight::get('sidecar.landing') ?? '/');

        $token  = (string) (Flight::request()->query->token ?? '');
        $claims = $token !== '' ? Token::verify($token, $secret, $name) : null;
        if (!$claims) { $this->deny('This sign-in link is invalid or has expired.'); return; }

        // Burn the nonce single-use.
        $nonce = (string) $claims['nonce'];
        if (Bean::findOne('ssononce', 'nonce = ?', [$nonce])) {
            $this->deny('This sign-in link has already been used.'); return;
        }
        $n = Bean::dispense('ssononce');
        $n->nonce = $nonce;
        $n->expiresAt = date('Y-m-d H:i:s', (int) $claims['exp']);
        $n->createdAt = date('Y-m-d H:i:s');
        Bean::store($n);
        $this->pruneNonces();

        // Re-verify member + feature grant against CORE (never trust the token alone).
        $core = Kernel::coreDb();
        if (!$core) { $this->deny('This plugin cannot reach the core directory right now.'); return; }
        $access = new Access($core);
        $memberId = (int) $claims['member_id'];
        $member = $access->memberIfActive($memberId);
        if (!$member) { $this->deny('Your account is not active.'); return; }
        if (!$access->featureEnabled($memberId, $feature)) { $this->deny('This feature is not enabled for your account.'); return; }

        session_regenerate_id(true);
        $_SESSION[$name] = [
            'member_id'  => $memberId,
            'level'      => (int) $member['level'],
            'email'      => (string) $member['email'],
            'checked_at' => time(),
        ];
        Flight::redirect($landing);
    }

    /** GET /sso/logout — drop the session. */
    public function logout($params = []) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        Flight::redirect('/');
    }

    /** The current plugin session, or null. Static helper for plugin controllers. */
    public static function session(): ?array {
        return $_SESSION[Kernel::name()] ?? null;
    }

    protected function deny(string $msg): void {
        $brand = htmlspecialchars((string) (Flight::get('app.name') ?? 'Sidecar'));
        Flight::halt(403, '<!doctype html><meta charset="utf-8"><title>' . $brand . '</title>'
            . '<body style="font-family:system-ui;background:#0b1530;color:#eaedf5;display:flex;'
            . 'min-height:100vh;align-items:center;justify-content:center;text-align:center">'
            . '<div><h1 style="font-weight:800">' . $brand . '</h1><p style="color:#9ba4bd">'
            . htmlspecialchars($msg) . '</p></div>');
    }

    private function pruneNonces(): void {
        try {
            foreach (Bean::find('ssononce', 'expires_at < ?', [date('Y-m-d H:i:s')]) as $old) Bean::trash($old);
        } catch (\Throwable $e) {}
    }
}
