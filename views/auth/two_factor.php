<?php use Twitkey\Core\Helpers; ?>
<div class="auth-card two-factor-card">
    <h1>Two-factor authentication</h1>
    <p class="auth-switch">Finish signing in to @<?= Helpers::h($user['username']) ?>.</p>

    <?php if ((int)($user['totp_enabled'] ?? 0) === 1): ?>
        <form action="/login/2fa/totp" method="post" class="auth-form">
            <?= Helpers::csrfField() ?>
            <label>Google Authenticator code
                <input type="text" name="code" inputmode="numeric" pattern="[0-9 ]{6,12}" autocomplete="one-time-code" required>
            </label>
            <button type="submit" class="primary-button full-button">Verify code</button>
        </form>
    <?php endif; ?>

    <?php if ($passkeyCount > 0): ?>
        <div class="passkey-login">
            <button type="button" class="secondary-button full-button" data-passkey-login>Use a passkey</button>
            <div class="tool-hint" data-passkey-login-status></div>
        </div>
    <?php endif; ?>

    <p class="auth-switch"><a href="/logout">Cancel sign in</a></p>
</div>
