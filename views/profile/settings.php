<?php use Twitkey\Core\Helpers; ?>
<div class="content-header">
    <h1>Settings</h1>
</div>
<form action="/settings" method="post" enctype="multipart/form-data" class="settings-form">
    <?= Helpers::csrfField() ?>
    <label>Full name
        <input type="text" name="display_name" maxlength="80" value="<?= Helpers::h($user['display_name']) ?>" required>
    </label>
    <label>Email
        <input type="email" name="email" maxlength="190" value="<?= Helpers::h($user['email']) ?>" required>
    </label>
    <label>Bio
        <textarea name="bio" maxlength="160"><?= Helpers::h($user['bio']) ?></textarea>
    </label>
    <label>Location
        <input type="text" name="location" maxlength="80" value="<?= Helpers::h($user['location']) ?>">
    </label>
    <label>Website
        <input type="url" name="website" maxlength="120" value="<?= Helpers::h($user['website']) ?>">
    </label>
    <label>Avatar
        <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" data-crop-input="avatar">
    </label>
    <input type="hidden" name="avatar_crop_x" data-crop-field="avatar:x">
    <input type="hidden" name="avatar_crop_y" data-crop-field="avatar:y">
    <input type="hidden" name="avatar_crop_w" data-crop-field="avatar:w">
    <input type="hidden" name="avatar_crop_h" data-crop-field="avatar:h">
    <div class="image-cropper" data-cropper="avatar" data-crop-aspect="1" hidden>
        <div class="crop-title">Crop profile picture</div>
        <div class="crop-stage" data-crop-stage>
            <img src="" data-crop-image alt="">
            <span class="crop-box" data-crop-box></span>
        </div>
        <div class="crop-hint">Drag the box to choose how your profile picture should be framed.</div>
    </div>
    <label>Profile banner
        <input type="file" name="banner" accept="image/jpeg,image/png,image/gif,image/webp" data-crop-input="banner">
    </label>
    <input type="hidden" name="banner_crop_x" data-crop-field="banner:x">
    <input type="hidden" name="banner_crop_y" data-crop-field="banner:y">
    <input type="hidden" name="banner_crop_w" data-crop-field="banner:w">
    <input type="hidden" name="banner_crop_h" data-crop-field="banner:h">
    <div class="image-cropper" data-cropper="banner" data-crop-aspect="3" hidden>
        <div class="crop-title">Crop profile banner</div>
        <div class="crop-stage" data-crop-stage>
            <img src="" data-crop-image alt="">
            <span class="crop-box" data-crop-box></span>
        </div>
        <div class="crop-hint">Drag the wide box to position the banner exactly how it should appear.</div>
    </div>
    <div class="settings-section">
        <h2>Privacy</h2>
        <label class="checkbox-label">
            <input type="checkbox" name="is_private" value="1"<?= (int)($user['is_private'] ?? 0) === 1 ? ' checked' : '' ?>>
            Private account mode
        </label>
        <label>Who can follow you
            <select name="follow_privacy">
                <option value="everyone"<?= ($user['follow_privacy'] ?? 'everyone') === 'everyone' ? ' selected' : '' ?>>Anyone</option>
                <option value="approve"<?= ($user['follow_privacy'] ?? 'everyone') === 'approve' ? ' selected' : '' ?>>People I approve</option>
            </select>
        </label>
        <label>Who can see your posts
            <select name="post_visibility">
                <option value="public"<?= ($user['post_visibility'] ?? 'public') === 'public' ? ' selected' : '' ?>>Everyone</option>
                <option value="followers"<?= ($user['post_visibility'] ?? 'public') === 'followers' ? ' selected' : '' ?>>Followers only</option>
            </select>
        </label>
        <label>Who can message you
            <select name="dm_privacy">
                <option value="everyone"<?= ($user['dm_privacy'] ?? 'mutuals') === 'everyone' ? ' selected' : '' ?>>Anyone</option>
                <option value="mutuals"<?= ($user['dm_privacy'] ?? 'mutuals') === 'mutuals' ? ' selected' : '' ?>>Mutual followers</option>
                <option value="none"<?= ($user['dm_privacy'] ?? 'mutuals') === 'none' ? ' selected' : '' ?>>No one</option>
            </select>
        </label>
        <div class="tool-hint">Private account mode forces approved follows and followers-only posts.</div>
    </div>
    <div class="settings-section">
        <h2>Theme</h2>
        <label>Site theme
            <?php $selectedTheme = (string)($user['theme_choice'] ?? $user['theme'] ?? 'classic'); ?>
            <select name="theme" data-theme-select>
                <?php foreach (Helpers::themes() as $themeValue => $themeLabel): ?>
                    <option value="<?= Helpers::h($themeValue) ?>"<?= $selectedTheme === $themeValue ? ' selected' : '' ?>><?= Helpers::h($themeLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="custom-theme-panel" data-custom-theme-panel<?= $selectedTheme === 'custom' ? '' : ' hidden' ?>>
            <label>Custom theme CSS
                <textarea name="custom_theme_css" class="custom-theme-editor" maxlength="20000" spellcheck="false"><?= Helpers::h(((string)($user['custom_theme_css'] ?? '')) !== '' ? (string)$user['custom_theme_css'] : Helpers::customThemeTemplate()) ?></textarea>
            </label>
            <div class="tool-hint">Use the Custom CSS theme to apply this stylesheet. Base stylesheet: <a href="/css/twitkey.css" target="_blank" rel="noopener">/css/twitkey.css</a>.</div>
        </div>
    </div>
    <button type="submit" class="primary-button">Save settings</button>
</form>

<div class="content-header secondary-heading">
    <h1>Two-Factor Authentication</h1>
</div>
<div class="settings-form two-factor-settings">
    <div class="settings-section">
        <h2>Google Authenticator</h2>
        <?php if ((int)($user['totp_enabled'] ?? 0) === 1): ?>
            <div class="security-status enabled">Enabled</div>
            <form action="/settings/2fa/totp/disable" method="post" class="compact-form">
                <?= Helpers::csrfField() ?>
                <label>Current code
                    <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
                </label>
                <button type="submit" class="secondary-button">Disable Google Authenticator</button>
            </form>
        <?php elseif ($totpSecret !== ''): ?>
            <div class="security-status pending">Waiting for confirmation</div>
            <p class="tool-hint">Add this setup key in Google Authenticator, then enter the six-digit code it shows.</p>
            <div class="totp-secret"><?= Helpers::h($totpSecret) ?></div>
            <div class="tool-hint break-word"><?= Helpers::h($totpUrl) ?></div>
            <form action="/settings/2fa/totp/enable" method="post" class="compact-form">
                <?= Helpers::csrfField() ?>
                <label>Authenticator code
                    <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
                </label>
                <button type="submit" class="primary-button">Enable Google Authenticator</button>
            </form>
        <?php else: ?>
            <div class="security-status">Not enabled</div>
            <form action="/settings/2fa/totp/create" method="post" class="compact-form">
                <?= Helpers::csrfField() ?>
                <button type="submit" class="primary-button">Create Google Authenticator setup key</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="settings-section">
        <h2>Passkeys</h2>
        <button type="button" class="primary-button" data-passkey-register>Add passkey</button>
        <div class="tool-hint" data-passkey-register-status></div>
        <?php if ($passkeys === []): ?>
            <div class="empty-state">No passkeys are registered for this account.</div>
        <?php else: ?>
            <?php foreach ($passkeys as $passkey): ?>
                <div class="passkey-row">
                    <div>
                        <strong><?= Helpers::h($passkey['name']) ?></strong>
                        <div class="muted">Created <?= Helpers::h($passkey['created_at']) ?><?= $passkey['last_used_at'] ? ' · Last used ' . Helpers::h($passkey['last_used_at']) : '' ?></div>
                    </div>
                    <form action="/settings/2fa/passkeys/<?= (int)$passkey['id'] ?>/delete" method="post">
                        <?= Helpers::csrfField() ?>
                        <button type="submit" class="mini-button" data-confirm="Remove this passkey?">Remove</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="content-header secondary-heading">
    <h1>Account Switching</h1>
</div>
<div class="account-switcher-settings">
    <?php foreach (($linkedAccounts ?? []) as $account): ?>
        <div class="account-switch-row<?= (int)$account['id'] === (int)$user['id'] ? ' active' : '' ?>">
            <span class="avatar-frame small-avatar-frame">
                <img src="<?= Helpers::avatarUrl($account) ?>" class="small-avatar" alt="">
                <?= Helpers::adminAvatarBadge($account) ?>
            </span>
            <div>
                <?= Helpers::renderUserName($account) ?>
                <div class="muted">@<?= Helpers::h($account['username']) ?><?= (int)$account['id'] === (int)$user['id'] ? ' · current' : '' ?></div>
            </div>
            <?php if ((int)$account['id'] !== (int)$user['id']): ?>
                <form action="/accounts/switch/<?= (int)$account['id'] ?>" method="post">
                    <?= Helpers::csrfField() ?>
                    <button type="submit" class="mini-button">Switch</button>
                </form>
                <form action="/accounts/remove/<?= (int)$account['id'] ?>" method="post">
                    <?= Helpers::csrfField() ?>
                    <button type="submit" class="mini-button">Remove</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <form action="/accounts/add" method="post" class="settings-form compact-form account-add-form">
        <?= Helpers::csrfField() ?>
        <label>Username or email
            <input type="text" name="login" autocomplete="username">
        </label>
        <label>Password
            <input type="password" name="password" autocomplete="current-password">
        </label>
        <button type="submit" class="primary-button">Add account</button>
    </form>
</div>

<div class="content-header secondary-heading">
    <h1>Affiliated Accounts</h1>
</div>
<?php if (($user['verified_type'] ?? null) === 'business'): ?>
    <form action="/settings/affiliations" method="post" class="settings-form compact-form">
        <?= Helpers::csrfField() ?>
        <input type="hidden" name="action" value="invite">
        <label>Invite @username
            <input type="text" name="username" maxlength="16" placeholder="@username">
        </label>
        <button type="submit" class="primary-button">Send Affiliation Invite</button>
    </form>
    <h2>Sent invites</h2>
    <?php foreach ($sentAffiliations as $aff): ?>
        <div class="user-result">
            <span class="avatar-frame small-avatar-frame">
                <img src="<?= Helpers::avatarUrl($aff) ?>" class="small-avatar" alt="">
                <?= Helpers::adminAvatarBadge($aff) ?>
            </span>
            <div><?= Helpers::renderUserName($aff) ?><div class="muted">Status: <?= Helpers::h($aff['status']) ?></div></div>
            <?php if ($aff['status'] !== 'revoked'): ?>
                <form action="/settings/affiliations" method="post">
                    <?= Helpers::csrfField() ?>
                    <input type="hidden" name="action" value="revoke">
                    <input type="hidden" name="affiliation_id" value="<?= (int)$aff['id'] ?>">
                    <button type="submit" class="mini-button">Revoke</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<h2>Pending invites</h2>
<?php if ($pendingAffiliations === []): ?>
    <div class="empty-state">No pending affiliation invites.</div>
<?php else: ?>
    <?php foreach ($pendingAffiliations as $aff): ?>
        <div class="user-result">
            <span class="avatar-frame small-avatar-frame">
                <img src="<?= Helpers::avatarUrl($aff) ?>" class="small-avatar" alt="">
                <?= Helpers::adminAvatarBadge($aff) ?>
            </span>
            <div><?= Helpers::renderUserName($aff) ?><div class="muted">@<?= Helpers::h($aff['username']) ?> wants to affiliate with you.</div></div>
            <form action="/settings/affiliations" method="post" class="button-row">
                <?= Helpers::csrfField() ?>
                <input type="hidden" name="affiliation_id" value="<?= (int)$aff['id'] ?>">
                <button type="submit" name="action" value="accept" class="mini-button">Accept</button>
                <button type="submit" name="action" value="decline" class="mini-button">Decline</button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
