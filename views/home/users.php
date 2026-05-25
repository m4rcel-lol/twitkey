<?php use Twitkey\Core\Helpers; ?>
<div class="content-header">
    <h1>Users</h1>
</div>

<section class="users-directory">
    <h2>Featured Users</h2>
    <?php if ($featuredUsers === []): ?>
        <div class="empty-state">No featured users yet.</div>
    <?php else: ?>
        <div class="featured-users">
            <?php foreach ($featuredUsers as $user): ?>
                <article class="featured-user">
                    <a href="/<?= Helpers::h($user['username']) ?>" class="featured-avatar-link">
                        <span class="avatar-frame featured-avatar-frame">
                            <img src="<?= Helpers::avatarUrl($user) ?>" class="featured-avatar" alt="">
                            <?= Helpers::adminAvatarBadge($user) ?>
                        </span>
                    </a>
                    <div class="featured-user-body">
                        <?= Helpers::renderUserName($user) ?>
                        <div class="muted">@<?= Helpers::h($user['username']) ?></div>
                        <p><?= Helpers::h(Helpers::truncate((string)($user['bio'] ?: 'No bio yet.'), 95)) ?></p>
                        <div class="directory-stats">
                            <span><?= number_format((int)$user['follower_count']) ?> followers</span>
                            <span><?= number_format((int)$user['tweet_count']) ?> posts</span>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2>All Registered Users</h2>
    <?php if ($users === []): ?>
        <div class="empty-state">No users to show.</div>
    <?php else: ?>
        <div class="directory-users">
            <?php foreach ($users as $user): ?>
                <article class="user-result directory-user">
                    <a href="/<?= Helpers::h($user['username']) ?>">
                        <span class="avatar-frame small-avatar-frame">
                            <img src="<?= Helpers::avatarUrl($user) ?>" class="small-avatar" alt="">
                            <?= Helpers::adminAvatarBadge($user) ?>
                        </span>
                    </a>
                    <div class="directory-user-body">
                        <?= Helpers::renderUserName($user) ?>
                        <div class="muted">
                            @<?= Helpers::h($user['username']) ?>
                        </div>
                        <p><?= Helpers::h(Helpers::truncate((string)($user['bio'] ?: 'No bio yet.'), 120)) ?></p>
                        <div class="directory-stats">
                            <span><?= number_format((int)$user['following_count']) ?> following</span>
                            <span><?= number_format((int)$user['follower_count']) ?> followers</span>
                            <span><?= number_format((int)$user['tweet_count']) ?> posts</span>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<nav class="pagination">
    <?php if ($page > 1): ?>
        <a href="/users?page=<?= $page - 1 ?>">newer</a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
    <?php if (count($users) >= 30): ?>
        <a href="/users?page=<?= $page + 1 ?>">older</a>
    <?php endif; ?>
</nav>
