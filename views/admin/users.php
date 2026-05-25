<?php
use Twitkey\Core\Helpers;
use Twitkey\Models\User;
?>
<nav class="admin-nav">
    <a href="/admin">Dashboard</a>
    <a href="/admin/users" class="active">Manage Users</a>
    <a href="/admin/tweets">Manage Tweets</a>
    <a href="/admin/notes">Community Notes</a>
</nav>
<?php if (empty($adminSecurityFresh)): ?>
    <div class="admin-security-banner">Admin actions are locked. <a href="/admin/verify">Verify 2FA</a> to edit users.</div>
<?php endif; ?>
<div class="content-header">
    <h1>Manage Users</h1>
</div>
<table class="admin-table admin-users-table">
    <thead>
    <tr><th>ID</th><th>Avatar</th><th>Username</th><th>Role</th><th>Verified</th><th>Admin</th><th>Status</th><th>Reviewed</th><th>Joined</th><th>Actions</th></tr>
    </thead>
    <tbody>
<?php foreach ($users as $user): ?>
        <?php
        $isOwner = User::isOwnerRow($user);
        $isSelf = $currentUser && (int)$currentUser['id'] === (int)$user['id'];
        $currentIsOwner = User::isOwnerRow($currentUser ?? null);
        $isAdminTarget = (int)($user['is_admin'] ?? 0) === 1;
        $canResetPassword = !$isAdminTarget || $currentIsOwner;
        $verifiedLabel = $isOwner ? 'owner' : ($user['verified_type'] ?: ((int)($user['is_verified'] ?? 0) === 1 ? 'normal' : '-'));
        ?>
        <tr>
            <td><?= (int)$user['id'] ?></td>
            <td>
                <span class="avatar-frame tiny-avatar-frame">
                    <img src="<?= Helpers::avatarUrl($user) ?>" class="tiny-avatar" alt="">
                    <?= Helpers::adminAvatarBadge($user) ?>
                </span>
            </td>
            <td><?= Helpers::renderUserName($user) ?><div class="muted">@<?= Helpers::h($user['username']) ?></div></td>
            <td><?= $isOwner ? 'owner' : Helpers::h($user['role']) ?></td>
            <td><?= Helpers::h($verifiedLabel) ?></td>
            <td><?= (int)$user['is_admin'] === 1 ? 'yes' : 'no' ?></td>
            <td>
                <?php if ((int)($user['is_deleted'] ?? 0) === 1): ?>
                    deleted
                <?php elseif ((int)$user['is_suspended'] === 1): ?>
                    suspended
                <?php else: ?>
                    active
                <?php endif; ?>
                <?php if (!empty($user['moderation_reason']) || !empty($user['suspension_reason'])): ?>
                    <div class="muted"><?= Helpers::h($user['moderation_reason'] ?: $user['suspension_reason']) ?></div>
                <?php endif; ?>
            </td>
            <td><?= Helpers::h($user['moderation_reviewed_at'] ?? '') ?></td>
            <td><?= Helpers::h($user['created_at']) ?></td>
            <td>
                <?php if ($isOwner): ?>
                    <div class="owner-protected-note">Owner account is protected.</div>
                <?php else: ?>
                    <form action="/admin/users/<?= (int)$user['id'] ?>/action" method="post" class="admin-actions">
                        <?= Helpers::csrfField() ?>
                        <button name="action" value="<?= (int)$user['is_admin'] === 1 ? 'revoke_admin' : 'grant_admin' ?>"<?= $isSelf && (int)$user['is_admin'] === 1 ? ' disabled' : '' ?>><?= $isSelf && (int)$user['is_admin'] === 1 ? 'Cannot Revoke Self' : ((int)$user['is_admin'] === 1 ? 'Revoke Admin' : 'Grant Admin') ?></button>
                        <button name="action" value="verify_normal">Verify Normal</button>
                        <button name="action" value="verify_business">Verify Business</button>
                        <button name="action" value="verify_government">Verify Government</button>
                        <button name="action" value="remove_verification">Remove Verification</button>
                        <input type="text" name="new_username" maxlength="15" placeholder="New username">
                        <button name="action" value="change_username">Change Username</button>
                        <input type="password" name="new_password" minlength="8" placeholder="<?= $canResetPassword ? 'New password' : 'Owner-only admin reset' ?>"<?= $canResetPassword ? '' : ' disabled' ?>>
                        <button name="action" value="reset_password"<?= $canResetPassword ? '' : ' disabled title="Only the Twitkey owner can reset administrator passwords."' ?>><?= $canResetPassword ? 'Reset Password' : 'Owner Only Reset' ?></button>
                        <input type="text" name="suspension_reason" maxlength="240" placeholder="Suspension reason">
                        <button name="action" value="<?= (int)$user['is_suspended'] === 1 ? 'unsuspend' : 'suspend' ?>"><?= (int)$user['is_suspended'] === 1 ? 'Unsuspend' : 'Ban Account' ?></button>
                        <button name="action" value="delete" data-confirm="Mark this account as deleted?">Mark Deleted</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
