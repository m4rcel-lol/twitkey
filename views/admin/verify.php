<?php use Twitkey\Core\Helpers; ?>
<nav class="admin-nav">
    <a href="/admin">Dashboard</a>
    <a href="/admin/users">Manage Users</a>
    <a href="/admin/tweets">Manage Tweets</a>
    <a href="/admin/notes">Community Notes</a>
</nav>
<div class="admin-auth-shell">
    <section class="admin-auth-card">
        <div class="admin-auth-kicker">Privileged Action Gate</div>
        <h1>Confirm administrator 2FA</h1>
        <p>Admin changes are locked until you verify a recent second factor. The unlock window lasts 10 minutes.</p>

        <?php if ((int)($admin['totp_enabled'] ?? 0) === 1): ?>
            <form action="/admin/verify" method="post" class="settings-form compact-form admin-auth-form">
                <?= Helpers::csrfField() ?>
                <label>Google Authenticator code
                    <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                </label>
                <button type="submit" class="primary-button">Verify code</button>
            </form>
        <?php endif; ?>

        <?php if ((int)$passkeyCount > 0): ?>
            <div class="passkey-admin-box">
                <button type="button" class="primary-button" data-admin-passkey-verify>Verify with passkey</button>
                <span class="form-status" data-admin-passkey-status></span>
            </div>
        <?php endif; ?>

        <div class="admin-auth-links">
            <a href="/settings">Manage 2FA settings</a>
            <a href="/admin">Back to admin dashboard</a>
        </div>
    </section>
</div>
