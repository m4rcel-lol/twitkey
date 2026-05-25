<?php
declare(strict_types=1);

namespace Twitkey\Controllers;

use Twitkey\Core\Auth;
use Twitkey\Core\Helpers;
use Twitkey\Core\Session;
use Twitkey\Models\TwoFactor;
use Twitkey\Models\User;

final class AuthController
{
    /**
     * Show the sign-in form.
     */
    public function loginForm(): void
    {
        Helpers::render('auth/login', ['title' => 'Sign in', 'hideSidebar' => true]);
    }

    /**
     * Authenticate a user.
     */
    public function login(): void
    {
        Helpers::verifyCsrf();
        $login = trim((string)($_POST['login'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $remember = isset($_POST['remember_me']);
        $user = $login !== '' && $password !== '' ? Auth::validateCredentials($login, $password) : null;
        if (!$user) {
            Session::flash('error', 'Invalid username/email or password.');
            Helpers::redirect('/login');
        }
        if (TwoFactor::isEnabled($user)) {
            $_SESSION['2fa_user_id'] = (int)$user['id'];
            $_SESSION['2fa_started_at'] = time();
            $_SESSION['2fa_remember_me'] = $remember ? 1 : 0;
            Helpers::redirect('/login/2fa');
        }
        Auth::loginAs($user, true);
        if ($remember) {
            Auth::remember($user);
        }
        Helpers::redirect('/');
    }

    /**
     * Return passkey request options for passwordless login.
     */
    public function passwordlessPasskeyOptions(): void
    {
        Helpers::json(['ok' => true, 'options' => TwoFactor::passwordlessPasskeyRequestOptions()]);
    }

    /**
     * Complete login with a discoverable passkey.
     */
    public function verifyPasswordlessPasskey(): void
    {
        Helpers::verifyCsrf();
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            Helpers::json(['ok' => false, 'error' => 'Invalid passkey response.'], 400);
        }
        try {
            $user = TwoFactor::verifyPasswordlessPasskeyAssertion($payload);
            Auth::loginAs($user, true);
            Helpers::json(['ok' => true, 'redirect' => '/']);
        } catch (\Throwable $e) {
            Helpers::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Show the second-factor login challenge.
     */
    public function twoFactorForm(): void
    {
        $user = $this->pendingTwoFactorUser();
        Helpers::render('auth/two_factor', [
            'title' => 'Two-factor authentication',
            'hideSidebar' => true,
            'user' => $user,
            'passkeyCount' => TwoFactor::passkeyCount((int)$user['id']),
        ]);
    }

    /**
     * Complete login with a Google Authenticator code.
     */
    public function verifyTwoFactorTotp(): void
    {
        Helpers::verifyCsrf();
        $user = $this->pendingTwoFactorUser();
        if (!TwoFactor::verifyTotp((string)($user['totp_secret'] ?? ''), (string)($_POST['code'] ?? ''))) {
            Session::flash('error', 'Authenticator code is invalid.');
            Helpers::redirect('/login/2fa');
        }
        $this->completeTwoFactorLogin($user);
    }

    /**
     * Return passkey request options for the pending login.
     */
    public function passkeyLoginOptions(): void
    {
        $user = $this->pendingTwoFactorUser();
        try {
            Helpers::json(['ok' => true, 'options' => TwoFactor::passkeyRequestOptions((int)$user['id'])]);
        } catch (\Throwable $e) {
            Helpers::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Complete login after verifying a passkey assertion.
     */
    public function verifyPasskeyLogin(): void
    {
        Helpers::verifyCsrf();
        $user = $this->pendingTwoFactorUser();
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            Helpers::json(['ok' => false, 'error' => 'Invalid passkey response.'], 400);
        }
        try {
            TwoFactor::verifyPasskeyAssertion((int)$user['id'], $payload);
            $this->completeTwoFactorLogin($user, true);
        } catch (\Throwable $e) {
            Helpers::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Add another account to the current browser session.
     */
    public function addAccount(): void
    {
        Helpers::verifyCsrf();
        Auth::requireLogin();
        $login = trim((string)($_POST['login'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $user = Auth::validateCredentials($login, $password);
        if (!$user) {
            Session::flash('error', 'Could not add that account. Check the username/email and password.');
            Helpers::redirect('/settings');
        }
        if (TwoFactor::isEnabled($user)) {
            Session::flash('error', 'That account uses two-factor authentication. Sign in to it directly first, then it can be switched in this browser session.');
            Helpers::redirect('/settings');
        }
        Auth::rememberAccount($user);
        Session::flash('success', '@' . $user['username'] . ' was added to account switching.');
        Helpers::redirect('/settings');
    }

    /**
     * Switch to a remembered account.
     */
    public function switchAccount(string $id): void
    {
        Helpers::verifyCsrf();
        Auth::requireLogin();
        if (!Auth::switchTo((int)$id)) {
            Session::flash('error', 'That account is not available for switching.');
            Helpers::redirect('/settings');
        }
        Helpers::redirect('/');
    }

    /**
     * Remove an account from the current browser session.
     */
    public function removeAccount(string $id): void
    {
        Helpers::verifyCsrf();
        Auth::requireLogin();
        Auth::forgetAccount((int)$id);
        Session::flash('success', 'Account removed from this browser session.');
        Helpers::redirect('/settings');
    }

    /**
     * Show the registration form.
     */
    public function registerForm(): void
    {
        Helpers::render('auth/register', ['title' => 'Join Twitkey', 'hideSidebar' => true]);
    }

    /**
     * Register and sign in a user.
     */
    public function register(): void
    {
        Helpers::verifyCsrf();
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');

        $errors = [];
        if ($displayName === '' || Helpers::mbLength($displayName) > 80) {
            $errors[] = 'Full name is required and must be 80 characters or less.';
        }
        if (!User::usernameAvailable($username)) {
            $errors[] = 'Username is invalid or already taken.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if (Helpers::mbLength($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                Session::flash('error', $error);
            }
            Helpers::redirect('/register');
        }

        try {
            User::create([
                'username' => $username,
                'display_name' => $displayName,
                'email' => $email,
                'password' => $password,
            ]);
            Auth::attempt($username, $password);
            Helpers::redirect('/');
        } catch (\Throwable) {
            Session::flash('error', 'That username or email address is already in use.');
            Helpers::redirect('/register');
        }
    }

    /**
     * End the current session.
     */
    public function logout(): void
    {
        Auth::logout();
        Helpers::redirect('/public');
    }

    /**
     * Return the pending second-factor user or redirect to login.
     *
     * @return array<string, mixed>
     */
    private function pendingTwoFactorUser(): array
    {
        $userId = (int)($_SESSION['2fa_user_id'] ?? 0);
        $startedAt = (int)($_SESSION['2fa_started_at'] ?? 0);
        $user = $userId > 0 && time() - $startedAt <= 600 ? User::find($userId) : null;
        if (!$user || !TwoFactor::isEnabled($user)) {
            unset($_SESSION['2fa_user_id'], $_SESSION['2fa_started_at'], $_SESSION['2fa_remember_me'], $_SESSION['passkey_login_challenge'], $_SESSION['passkey_login_origin']);
            Helpers::redirect('/login');
        }
        return $user;
    }

    /**
     * Promote a pending second-factor session into a normal login.
     *
     * @param array<string, mixed> $user
     */
    private function completeTwoFactorLogin(array $user, bool $json = false): void
    {
        $remember = (int)($_SESSION['2fa_remember_me'] ?? 0) === 1;
        unset($_SESSION['2fa_user_id'], $_SESSION['2fa_started_at'], $_SESSION['2fa_remember_me'], $_SESSION['passkey_login_challenge'], $_SESSION['passkey_login_origin']);
        Auth::loginAs($user, true);
        if ($remember) {
            Auth::remember($user);
        }
        if ($json) {
            Helpers::json(['ok' => true, 'redirect' => '/']);
        }
        Helpers::redirect('/');
    }
}
