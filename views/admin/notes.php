<?php use Twitkey\Core\Helpers; ?>
<nav class="admin-nav">
    <a href="/admin">Dashboard</a>
    <a href="/admin/users">Manage Users</a>
    <a href="/admin/tweets">Manage Tweets</a>
    <a href="/admin/notes" class="active">Community Notes</a>
</nav>
<?php if (empty($adminSecurityFresh)): ?>
    <div class="admin-security-banner">Admin actions are locked. <a href="/admin/verify">Verify 2FA</a> to review notes.</div>
<?php endif; ?>
<div class="content-header">
    <h1>Community Notes</h1>
</div>
<table class="admin-table">
    <thead><tr><th>ID</th><th>Tweet</th><th>Note</th><th>Status</th><th>Votes</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($notes as $note): ?>
        <tr>
            <td><?= (int)$note['id'] ?></td>
            <td>@<?= Helpers::h($note['tweet_username']) ?>: <?= Helpers::h(Helpers::truncate((string)$note['tweet_body'], 70)) ?></td>
            <td><?= Helpers::h(Helpers::truncate((string)$note['body'], 120)) ?><div class="muted">by @<?= Helpers::h($note['author_username']) ?></div></td>
            <td><?= Helpers::h($note['status']) ?></td>
            <td><?= (int)$note['helpful_votes'] ?> helpful / <?= (int)$note['unhelpful_votes'] ?> unhelpful</td>
            <td>
                <form action="/admin/notes/<?= (int)$note['id'] ?>/action" method="post" class="admin-actions">
                    <?= Helpers::csrfField() ?>
                    <button name="action" value="approve">Approve</button>
                    <button name="action" value="reject">Reject</button>
                    <button name="action" value="delete" data-confirm="Delete this note?">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
