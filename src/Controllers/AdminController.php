<?php
declare(strict_types=1);

namespace Twitkey\Controllers;

use Twitkey\Core\Auth;
use Twitkey\Core\Database;
use Twitkey\Core\Helpers;
use Twitkey\Core\Session;
use Twitkey\Models\CommunityNote;
use Twitkey\Models\Tweet;
use Twitkey\Models\TwoFactor;
use Twitkey\Models\User;

final class AdminController
{
    /**
     * Show the admin dashboard.
     */
    public function dashboard(): void
    {
        $admin = $this->requireAdminSecurity(false);
        $db = Database::instance();
        $todaySql = $db->isMysql() ? 'DATE(created_at) = CURRENT_DATE' : "date(created_at) = date('now')";
        Helpers::render('admin/dashboard', [
            'title' => 'Admin',
            'stats' => [
                'users' => (int)($db->one('SELECT COUNT(*) AS c FROM users')['c'] ?? 0),
                'tweets_today' => (int)($db->one("SELECT COUNT(*) AS c FROM tweets WHERE {$todaySql}")['c'] ?? 0),
                'active' => (int)($db->one("SELECT COUNT(DISTINCT user_id) AS c FROM tweets WHERE {$todaySql}")['c'] ?? 0),
                'pending_notes' => (int)($db->one('SELECT COUNT(*) AS c FROM community_notes WHERE status = :status', ['status' => 'pending'])['c'] ?? 0),
                'suspended' => (int)($db->one('SELECT COUNT(*) AS c FROM users WHERE is_suspended = 1')['c'] ?? 0),
            ],
            'logs' => $db->all('SELECT l.*, u.username FROM admin_log l JOIN users u ON u.id = l.admin_id ORDER BY l.created_at DESC LIMIT 10'),
            'siteAlert' => $db->one('SELECT * FROM site_alerts ORDER BY updated_at DESC, id DESC LIMIT 1'),
            'maintenanceMode' => Helpers::maintenanceModeEnabled(),
            'isOwner' => User::isOwnerRow($admin),
            'adminSecurityFresh' => $this->adminSecurityFresh(),
            'wideLayout' => true,
            'adminLayout' => true,
        ]);
    }

    /**
     * Update the global site alert banner.
     */
    public function siteAlert(): void
    {
        Helpers::verifyCsrf();
        $admin = $this->requireAdminSecurity(true);
        $message = Helpers::mbLimit(trim((string)($_POST['message'] ?? '')), 240);
        $active = isset($_POST['is_active']) && $message !== '' ? 1 : 0;
        Database::instance()->execute('UPDATE site_alerts SET is_active = 0');
        Database::instance()->execute(
            'INSERT INTO site_alerts (message, is_active, updated_by, updated_at) VALUES (:message, :is_active, :updated_by, :updated_at)',
            ['message' => $message, 'is_active' => $active, 'updated_by' => (int)$admin['id'], 'updated_at' => date('Y-m-d H:i:s')]
        );
        $this->log((int)$admin['id'], 'site_alert_update', 'site_alert', Database::instance()->lastInsertId());
        Session::flash('success', 'Site alert updated.');
        Helpers::redirect('/admin');
    }

    /**
     * Toggle site maintenance mode. Only the configured owner can do this.
     */
    public function maintenance(): void
    {
        Helpers::verifyCsrf();
        $admin = $this->requireAdminSecurity(true);
        if (!User::isOwnerRow($admin)) {
            http_response_code(403);
            Session::flash('error', 'Only the Twitkey owner can change maintenance mode.');
            Helpers::redirect('/admin');
        }

        $enabled = (string)($_POST['maintenance_mode'] ?? '0') === '1';
        Helpers::setAppSetting('maintenance_mode', $enabled ? '1' : '0', (int)$admin['id']);
        $this->log((int)$admin['id'], $enabled ? 'maintenance_enable' : 'maintenance_disable', 'site', 0);
        Session::flash('success', $enabled ? 'Maintenance mode enabled.' : 'Maintenance mode disabled.');
        Helpers::redirect('/admin');
    }

    /**
     * Show manageable users.
     */
    public function users(): void
    {
        $this->requireAdminSecurity(false);
        Helpers::render('admin/users', ['title' => 'Manage Users', 'users' => User::adminList(Helpers::page()), 'page' => Helpers::page(), 'adminSecurityFresh' => $this->adminSecurityFresh(), 'wideLayout' => true, 'adminLayout' => true]);
    }

    /**
     * Apply an admin action to a user.
     */
    public function userAction(string $id): void
    {
        Helpers::verifyCsrf();
        $admin = $this->requireAdminSecurity(true);
        $userId = (int)$id;
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'revoke_admin' && $userId === (int)$admin['id']) {
                throw new \RuntimeException('Admins cannot revoke their own admin access.');
            }
            match ($action) {
                'grant_admin' => User::setAdmin($userId, true),
                'revoke_admin' => User::setAdmin($userId, false),
                'verify_normal' => User::setNormalVerified($userId, true),
                'verify_business' => User::setVerification($userId, 'business'),
                'verify_government' => User::setVerification($userId, 'government'),
                'remove_verification' => User::setVerification($userId, null),
                'suspend' => User::setSuspended($userId, true, (string)($_POST['suspension_reason'] ?? '')),
                'unsuspend' => User::setSuspended($userId, false),
                'delete' => User::delete($userId, (string)($_POST['suspension_reason'] ?? '')),
                'change_username' => User::changeUsername($userId, (string)($_POST['new_username'] ?? '')),
                'reset_password' => User::setPassword($userId, (string)($_POST['new_password'] ?? ''), $admin),
                default => throw new \InvalidArgumentException('Unknown admin action.'),
            };
            $this->log((int)$admin['id'], $action, 'user', $userId, (string)($_POST['suspension_reason'] ?? ''));
            Session::flash('success', 'User action applied.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        Helpers::redirect('/admin/users');
    }

    /**
     * Show manageable tweets.
     */
    public function tweets(): void
    {
        $this->requireAdminSecurity(false);
        Helpers::render('admin/tweets', ['title' => 'Manage Tweets', 'tweets' => Tweet::adminList(Helpers::page()), 'page' => Helpers::page(), 'adminSecurityFresh' => $this->adminSecurityFresh(), 'wideLayout' => true, 'adminLayout' => true]);
    }

    /**
     * Apply an admin action to a tweet.
     */
    public function tweetAction(string $id): void
    {
        Helpers::verifyCsrf();
        $admin = $this->requireAdminSecurity(true);
        $tweetId = (int)$id;
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'delete') {
                Tweet::delete($tweetId, (int)$admin['id'], true);
            } elseif ($action === 'remove_note') {
                CommunityNote::rejectForTweet($tweetId, (int)$admin['id']);
            } elseif ($action === 'add_note') {
                $bot = User::communityNotesBot();
                CommunityNote::addApproved($tweetId, (int)$bot['id'], (string)($_POST['body'] ?? ''), (int)$admin['id']);
            } else {
                throw new \InvalidArgumentException('Unknown tweet action.');
            }
            $this->log((int)$admin['id'], $action, 'tweet', $tweetId);
            Session::flash('success', 'Tweet action applied.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        Helpers::redirect('/admin/tweets');
    }

    /**
     * Show manageable community notes.
     */
    public function notes(): void
    {
        $this->requireAdminSecurity(false);
        Helpers::render('admin/notes', ['title' => 'Community Notes', 'notes' => CommunityNote::adminList(Helpers::page()), 'page' => Helpers::page(), 'adminSecurityFresh' => $this->adminSecurityFresh(), 'wideLayout' => true, 'adminLayout' => true]);
    }

    /**
     * Apply an admin action to a community note.
     */
    public function noteAction(string $id): void
    {
        Helpers::verifyCsrf();
        $admin = $this->requireAdminSecurity(true);
        $noteId = (int)$id;
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'approve') {
                CommunityNote::setStatus($noteId, 'approved', (int)$admin['id']);
            } elseif ($action === 'reject') {
                CommunityNote::setStatus($noteId, 'rejected', (int)$admin['id']);
            } elseif ($action === 'delete') {
                CommunityNote::delete($noteId);
            } else {
                throw new \InvalidArgumentException('Unknown note action.');
            }
            $this->log((int)$admin['id'], $action, 'note', $noteId);
            Session::flash('success', 'Note action applied.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        Helpers::redirect('/admin/notes');
    }

    /**
     * Show the fresh 2FA challenge required before privileged admin actions.
     */
    public function verifyForm(): void
    {
        $admin = Auth::requireAdmin();
        if (!TwoFactor::isEnabled($admin)) {
            Session::flash('error', 'Admins must enable Google Authenticator or a passkey before using admin tools.');
            Helpers::redirect('/settings');
        }
        if ($this->adminSecurityFresh()) {
            Helpers::redirect('/admin');
        }
        Helpers::render('admin/verify', [
            'title' => 'Confirm Admin 2FA',
            'admin' => $admin,
            'passkeyCount' => TwoFactor::passkeyCount((int)$admin['id']),
            'wideLayout' => true,
            'adminLayout' => true,
        ]);
    }

    /**
     * Verify an administrator's authenticator code for the current admin session.
     */
    public function verifyTotp(): void
    {
        Helpers::verifyCsrf();
        $admin = Auth::requireAdmin();
        if (!TwoFactor::isEnabled($admin)) {
            Session::flash('error', 'Set up 2FA before using admin tools.');
            Helpers::redirect('/settings');
        }
        if ((int)($admin['totp_enabled'] ?? 0) !== 1 || !TwoFactor::verifyTotp((string)($admin['totp_secret'] ?? ''), (string)($_POST['code'] ?? ''))) {
            Session::flash('error', 'Authenticator code is invalid.');
            Helpers::redirect('/admin/verify');
        }
        $_SESSION['admin_2fa_verified_at'] = time();
        Session::flash('success', 'Admin actions are unlocked for this session window.');
        Helpers::redirect('/admin');
    }

    /**
     * Return passkey request options for admin action confirmation.
     */
    public function passkeyVerifyOptions(): void
    {
        $admin = Auth::requireAdmin();
        if (!TwoFactor::isEnabled($admin)) {
            Helpers::json(['ok' => false, 'error' => 'Set up 2FA before using admin tools.'], 403);
        }
        try {
            Helpers::json(['ok' => true, 'options' => TwoFactor::passkeyRequestOptions((int)$admin['id'])]);
        } catch (\Throwable $e) {
            Helpers::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Verify a passkey assertion and unlock privileged admin actions.
     */
    public function verifyPasskey(): void
    {
        Helpers::verifyCsrf();
        $admin = Auth::requireAdmin();
        if (!TwoFactor::isEnabled($admin)) {
            Helpers::json(['ok' => false, 'error' => 'Set up 2FA before using admin tools.'], 403);
        }
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            Helpers::json(['ok' => false, 'error' => 'Invalid passkey response.'], 400);
        }
        try {
            TwoFactor::verifyPasskeyAssertion((int)$admin['id'], $payload);
            $_SESSION['admin_2fa_verified_at'] = time();
            Helpers::json(['ok' => true, 'redirect' => '/admin']);
        } catch (\Throwable $e) {
            Helpers::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Promote the first admin once using the setup token.
     */
    public function setup(): void
    {
        $flag = Database::instance()->dataDir() . '/.admin_setup_done';
        if (is_file($flag)) {
            http_response_code(404);
            Helpers::render('errors/404', ['title' => 'Not Found'], true);
            return;
        }
        $token = (string)($_GET['token'] ?? '');
        $username = (string)($_GET['username'] ?? '');
        if ($token === '' || !hash_equals(Helpers::env('ADMIN_SETUP_TOKEN', ''), $token) || $username === '') {
            http_response_code(403);
            Helpers::render('errors/403', ['title' => 'Forbidden'], true);
            return;
        }
        $user = User::findByUsername($username);
        if (!$user) {
            Session::flash('error', 'User not found.');
            Helpers::redirect('/login');
        }
        User::setAdmin((int)$user['id'], true);
        file_put_contents($flag, date(DATE_ATOM));
        Session::flash('success', '@' . $user['username'] . ' is now an admin.');
        Helpers::redirect('/' . rawurlencode((string)$user['username']));
    }

    /**
     * Require an administrator account with configured 2FA.
     *
     * @return array<string, mixed>
     */
    private function requireAdminSecurity(bool $requireFreshVerification): array
    {
        $admin = Auth::requireAdmin();
        if (!TwoFactor::isEnabled($admin)) {
            Session::flash('error', 'Admins must set up Google Authenticator or a passkey before using admin tools.');
            Helpers::redirect('/settings');
        }
        if ($requireFreshVerification && !$this->adminSecurityFresh()) {
            Session::flash('error', 'Confirm 2FA before taking an admin action.');
            Helpers::redirect('/admin/verify');
        }
        return $admin;
    }

    /**
     * True when the current admin recently completed the admin 2FA challenge.
     */
    private function adminSecurityFresh(): bool
    {
        $verifiedAt = (int)($_SESSION['admin_2fa_verified_at'] ?? 0);
        return $verifiedAt > 0 && $verifiedAt >= time() - 600;
    }

    /**
     * Write an admin action to the audit log.
     */
    private function log(int $adminId, string $action, string $targetType, int $targetId, string $note = ''): void
    {
        Database::instance()->execute(
            'INSERT INTO admin_log (admin_id, action, target_type, target_id, note) VALUES (:admin_id, :action, :target_type, :target_id, :note)',
            ['admin_id' => $adminId, 'action' => $action, 'target_type' => $targetType, 'target_id' => $targetId, 'note' => Helpers::mbLimit(trim($note), 240)]
        );
    }
}
