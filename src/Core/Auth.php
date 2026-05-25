<?php
declare(strict_types=1);

namespace Twitkey\Core;

use Twitkey\Models\User;

final class Auth
{
    private static ?array $cachedUser = null;

    /**
     * Return the logged-in user, or null for guests.
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }
        $id = (int)($_SESSION['user_id'] ?? 0);
        if ($id <= 0) {
            return self::loginFromRememberCookie();
        }
        self::$cachedUser = User::find($id);
        if (self::$cachedUser === null) {
            unset($_SESSION['user_id']);
            return self::loginFromRememberCookie();
        }
        return self::$cachedUser;
    }

    /**
     * Return the current user id, or null for guests.
     */
    public static function id(): ?int
    {
        $user = self::user();
        return $user ? (int)$user['id'] : null;
    }

    /**
     * Attempt a username/email and password login.
     */
    public static function attempt(string $login, string $password): bool
    {
        $user = User::findByLogin($login);
        if (!$user || (int)($user['is_system'] ?? 0) === 1 || !password_verify($password, (string)$user['password'])) {
            return false;
        }
        self::loginAs($user, true);
        return true;
    }

    /**
     * Return a user row when the supplied credentials are valid for a switchable account.
     *
     * @return array<string, mixed>|null
     */
    public static function validateCredentials(string $login, string $password): ?array
    {
        $user = User::findByLogin($login);
        if (!$user || (int)($user['is_system'] ?? 0) === 1 || !password_verify($password, (string)$user['password'])) {
            return null;
        }
        return $user;
    }

    /**
     * Set the active account and remember it in this browser session.
     *
     * @param array<string, mixed> $user
     */
    public static function loginAs(array $user, bool $regenerate = false): void
    {
        if ($regenerate) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['account_ids'] = self::normalizedAccountIds([(int)$user['id'], ...self::accountIds()]);
        self::$cachedUser = $user;
    }

    /**
     * Persist login in a secure long-lived cookie.
     *
     * @param array<string, mixed> $user
     */
    public static function remember(array $user): void
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + (60 * 60 * 24 * 60);
        Database::instance()->execute('DELETE FROM remember_tokens WHERE expires_at <= :now', ['now' => date('Y-m-d H:i:s')]);
        Database::instance()->execute(
            'INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (:user_id, :selector, :token_hash, :expires_at)',
            [
                'user_id' => (int)$user['id'],
                'selector' => $selector,
                'token_hash' => hash('sha256', $validator),
                'expires_at' => date('Y-m-d H:i:s', $expires),
            ]
        );
        self::setRememberCookie($selector . ':' . $validator, $expires);
    }

    /**
     * Log out the current session.
     */
    public static function logout(): void
    {
        self::forgetRememberCookie();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
        self::$cachedUser = null;
    }

    /**
     * Return switchable account ids remembered in this browser session.
     *
     * @return array<int, int>
     */
    public static function accountIds(): array
    {
        return self::normalizedAccountIds([(int)($_SESSION['user_id'] ?? 0), ...(array)($_SESSION['account_ids'] ?? [])]);
    }

    /**
     * Return switchable account rows for the active browser session.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function linkedAccounts(): array
    {
        $accounts = [];
        foreach (self::accountIds() as $id) {
            $user = User::find($id);
            if ($user && (int)($user['is_system'] ?? 0) !== 1) {
                $accounts[] = $user;
            }
        }
        return $accounts;
    }

    /**
     * Add an account to the current browser session without switching away.
     *
     * @param array<string, mixed> $user
     */
    public static function rememberAccount(array $user): void
    {
        $_SESSION['account_ids'] = self::normalizedAccountIds([(int)$user['id'], ...self::accountIds()]);
    }

    /**
     * Switch the active browser session to a remembered account id.
     */
    public static function switchTo(int $id): bool
    {
        if (!in_array($id, self::accountIds(), true)) {
            return false;
        }
        $user = User::find($id);
        if (!$user || (int)($user['is_system'] ?? 0) === 1) {
            return false;
        }
        self::loginAs($user, true);
        return true;
    }

    /**
     * Remove a remembered account from the browser session.
     */
    public static function forgetAccount(int $id): void
    {
        $_SESSION['account_ids'] = array_values(array_filter(self::accountIds(), static fn (int $accountId): bool => $accountId !== $id));
        if ((int)($_SESSION['user_id'] ?? 0) === $id) {
            unset($_SESSION['user_id']);
            self::$cachedUser = null;
        }
    }

    /**
     * Require a logged-in user or redirect to the login page.
     *
     * @return array<string, mixed>
     */
    public static function requireLogin(): array
    {
        $user = self::user();
        if (!$user) {
            Helpers::redirect('/login');
        }
        return $user;
    }

    /**
     * Require an active, non-suspended account for write actions.
     *
     * @return array<string, mixed>
     */
    public static function requireActiveUser(): array
    {
        $user = self::requireLogin();
        if ((int)$user['is_suspended'] === 1 || (int)($user['is_deleted'] ?? 0) === 1) {
            $reason = trim((string)($user['suspension_reason'] ?? ''));
            $message = 'This account has been suspended.' . ($reason !== '' ? ' Reason: ' . $reason : '');
            if (Helpers::wantsJson()) {
                Helpers::json(['ok' => false, 'error' => $message], 403);
            }
            Session::flash('error', $message);
            Helpers::redirect('/');
        }
        return $user;
    }

    /**
     * Require an administrator or render a 403 response.
     *
     * @return array<string, mixed>
     */
    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if ((int)$user['is_admin'] !== 1) {
            http_response_code(403);
            Helpers::render('errors/403', ['title' => 'Forbidden'], true);
            exit;
        }
        return $user;
    }

    /**
     * True if the current user is an administrator.
     */
    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user !== null && (int)$user['is_admin'] === 1;
    }

    /**
     * Clear the cached user after profile or privilege updates.
     */
    public static function clearCache(): void
    {
        self::$cachedUser = null;
    }

    /**
     * Normalize account ids stored in the session.
     *
     * @param mixed $ids
     * @return array<int, int>
     */
    private static function normalizedAccountIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return array_slice($out, 0, 8);
    }

    /**
     * Restore a session from a valid remember-me cookie.
     *
     * @return array<string, mixed>|null
     */
    private static function loginFromRememberCookie(): ?array
    {
        $cookie = (string)($_COOKIE['twitkey_remember'] ?? '');
        if ($cookie === '' || !str_contains($cookie, ':')) {
            return null;
        }
        [$selector, $validator] = explode(':', $cookie, 2);
        if (!preg_match('/^[a-f0-9]{18}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
            self::forgetRememberCookie();
            return null;
        }
        $row = Database::instance()->one(
            'SELECT rt.*, u.*
             FROM remember_tokens rt
             JOIN users u ON u.id = rt.user_id
             WHERE rt.selector = :selector
             LIMIT 1',
            ['selector' => $selector]
        );
        if (!$row || strtotime((string)$row['expires_at']) <= time() || !hash_equals((string)$row['token_hash'], hash('sha256', $validator))) {
            Database::instance()->execute('DELETE FROM remember_tokens WHERE selector = :selector', ['selector' => $selector]);
            self::forgetRememberCookie();
            return null;
        }
        if ((int)($row['is_system'] ?? 0) === 1 || (int)($row['is_suspended'] ?? 0) === 1 || (int)($row['is_deleted'] ?? 0) === 1) {
            self::forgetRememberCookie();
            return null;
        }

        $user = User::find((int)$row['user_id']);
        if (!$user) {
            self::forgetRememberCookie();
            return null;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['account_ids'] = self::normalizedAccountIds([(int)$user['id'], ...self::accountIds()]);
        self::$cachedUser = $user;

        $newValidator = bin2hex(random_bytes(32));
        $expires = time() + (60 * 60 * 24 * 60);
        Database::instance()->execute(
            'UPDATE remember_tokens SET token_hash = :token_hash, expires_at = :expires_at, last_used_at = :last_used_at WHERE selector = :selector',
            [
                'token_hash' => hash('sha256', $newValidator),
                'expires_at' => date('Y-m-d H:i:s', $expires),
                'last_used_at' => date('Y-m-d H:i:s'),
                'selector' => $selector,
            ]
        );
        self::setRememberCookie($selector . ':' . $newValidator, $expires);
        return $user;
    }

    /**
     * Clear the active remember-me token, if present.
     */
    private static function forgetRememberCookie(): void
    {
        $cookie = (string)($_COOKIE['twitkey_remember'] ?? '');
        if (str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);
            Database::instance()->execute('DELETE FROM remember_tokens WHERE selector = :selector', ['selector' => $selector]);
        }
        self::setRememberCookie('', time() - 3600);
    }

    /**
     * Write the remember-me cookie with security attributes.
     */
    private static function setRememberCookie(string $value, int $expires): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie('twitkey_remember', $value, [
            'expires' => $expires,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        if ($expires <= time()) {
            unset($_COOKIE['twitkey_remember']);
        } else {
            $_COOKIE['twitkey_remember'] = $value;
        }
    }
}
