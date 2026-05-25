<?php use Twitkey\Core\Helpers; ?>
<div class="auth-card">
    <h1>Sign in to Twitkey</h1>
    <form action="/login" method="post" class="auth-form">
        <?= Helpers::csrfField() ?>
        <label>Username or email
            <input type="text" name="login" autocomplete="username" required>
        </label>
        <label>Password
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <label class="checkbox-label auth-check">
            <input type="checkbox" name="remember_me" value="1">
            Remember me
        </label>
        <button type="submit" class="primary-button full-button">Sign in</button>
    </form>
    <div class="passkey-login primary-passkey-login">
        <button type="button" class="secondary-button full-button" data-passkey-login data-passkey-login-mode="passwordless">Login with passkey</button>
        <div class="tool-hint" data-passkey-login-status></div>
    </div>
    <p class="auth-switch">New to Twitkey? <a href="/register">Join today!</a></p>
</div>
