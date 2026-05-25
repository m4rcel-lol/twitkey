<?php use Twitkey\Core\Helpers; ?>
<nav class="admin-nav">
    <a href="/admin" class="active">Dashboard</a>
    <a href="/admin/users">Manage Users</a>
    <a href="/admin/tweets">Manage Tweets</a>
    <a href="/admin/notes">Community Notes</a>
</nav>
<?php if (empty($adminSecurityFresh)): ?>
    <div class="admin-security-banner">Admin actions are locked. <a href="/admin/verify">Verify 2FA</a> to make changes.</div>
<?php endif; ?>
<div class="content-header">
    <h1>Twitkey Admin Panel</h1>
</div>
<div class="admin-stats">
    <div>Total Users: <strong><?= number_format((int)$stats['users']) ?></strong></div>
    <div>Tweets Today: <strong><?= number_format((int)$stats['tweets_today']) ?></strong></div>
    <div>Active: <strong><?= number_format((int)$stats['active']) ?></strong></div>
    <div>Pending Notes: <strong><?= number_format((int)$stats['pending_notes']) ?></strong></div>
    <div>Suspended: <strong><?= number_format((int)$stats['suspended']) ?></strong></div>
</div>
<h2>Site Alert Banner</h2>
<form action="/admin/site-alert" method="post" class="settings-form compact-form">
    <?= Helpers::csrfField() ?>
    <label>Alert message
        <textarea name="message" maxlength="240"><?= Helpers::h($siteAlert['message'] ?? '') ?></textarea>
    </label>
    <label class="checkbox-label">
        <input type="checkbox" name="is_active" value="1"<?= !empty($siteAlert) && (int)$siteAlert['is_active'] === 1 ? ' checked' : '' ?>>
        Show alert banner
    </label>
    <button type="submit" class="primary-button">Update alert</button>
</form>
<h2>Maintenance Mode</h2>
<div class="maintenance-admin-panel">
    <p>Current status: <strong><?= !empty($maintenanceMode) ? 'enabled' : 'disabled' ?></strong></p>
    <?php if (!empty($isOwner)): ?>
        <form action="/admin/maintenance" method="post" class="settings-form compact-form">
            <?= Helpers::csrfField() ?>
            <input type="hidden" name="maintenance_mode" value="<?= !empty($maintenanceMode) ? '0' : '1' ?>">
            <button type="submit" class="primary-button" data-confirm="<?= !empty($maintenanceMode) ? 'Disable maintenance mode?' : 'Enable maintenance mode? Only the owner will be able to access the site.' ?>">
                <?= !empty($maintenanceMode) ? 'Disable maintenance mode' : 'Enable maintenance mode' ?>
            </button>
        </form>
    <?php else: ?>
        <div class="empty-state">Only the Twitkey owner can change maintenance mode.</div>
    <?php endif; ?>
</div>
<h2>Recent Admin Actions</h2>
<?php if ($logs === []): ?>
    <div class="empty-state">No admin actions logged.</div>
<?php else: ?>
    <table class="admin-table">
        <thead><tr><th>Admin</th><th>Action</th><th>Target</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td>@<?= Helpers::h($log['username']) ?></td>
                <td><?= Helpers::h($log['action']) ?></td>
                <td><?= Helpers::h($log['target_type']) ?> #<?= (int)$log['target_id'] ?></td>
                <td><?= Helpers::h($log['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
