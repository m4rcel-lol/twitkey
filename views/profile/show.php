<?php
use Twitkey\Core\Helpers;
use Twitkey\Models\User;
$bannerUrl = Helpers::bannerUrl($profile);
$isOwner = User::isOwnerRow($profile);
?>
<section class="profile-header<?= (int)$profile['is_admin'] === 1 ? ' admin-profile' : '' ?><?= $bannerUrl ? ' has-banner' : '' ?>"<?= $bannerUrl ? ' style="--profile-banner: url(' . Helpers::h($bannerUrl) . ');"' : '' ?>>
    <?php if ($bannerUrl): ?>
        <div class="profile-banner" aria-hidden="true"></div>
    <?php endif; ?>
    <?php if ((int)$profile['is_suspended'] === 1): ?>
        <div class="suspended-banner">
            This account has been suspended.
            <?php if (!empty($profile['suspension_reason'])): ?>
                <span><?= Helpers::h($profile['suspension_reason']) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <span class="avatar-frame profile-avatar-frame">
        <img src="<?= Helpers::avatarUrl($profile) ?>" class="profile-avatar" alt="">
        <?= Helpers::adminAvatarBadge($profile) ?>
    </span>
    <div class="profile-info">
        <h1><?= Helpers::h($profile['display_name']) ?> <?= Helpers::renderBadges($profile) ?><?= (int)($profile['is_private'] ?? 0) === 1 ? ' <span class="lock-badge tooltip-wrap" data-tooltip="Private account">🔒</span>' : '' ?></h1>
        <div class="profile-username">@<?= Helpers::h($profile['username']) ?><?= (int)($profile['is_private'] ?? 0) === 1 ? ' <span class="lock-badge tooltip-wrap" data-tooltip="Private account">🔒</span>' : '' ?> <?= Helpers::followsYouBadge($profile) ?></div>
        <?php if ($isOwner): ?>
            <div class="staff-label owner-label">This user is the owner of Twitkey.</div>
        <?php elseif ((int)$profile['is_admin'] === 1): ?>
            <div class="staff-label">This user is a Administrator of <?= Helpers::h(Helpers::env('APP_NAME', 'Twitkey')) ?></div>
        <?php endif; ?>
        <p><?= Helpers::h($profile['bio']) ?></p>
        <div class="profile-meta">
            <?php if ($profile['website']): ?><span>Web: <a href="<?= Helpers::h($profile['website']) ?>" rel="nofollow noopener" target="_blank"><?= Helpers::h($profile['website']) ?></a></span><?php endif; ?>
            <?php if ($profile['location']): ?><span>Location: <?= Helpers::h($profile['location']) ?></span><?php endif; ?>
        </div>
        <div class="profile-stats">
            <a href="/<?= Helpers::h($profile['username']) ?>/following">Following: <?= (int)$profile['following_count'] ?></a>
            <a href="/<?= Helpers::h($profile['username']) ?>/followers">Followers: <?= (int)$profile['follower_count'] ?></a>
            <span>Tweets: <?= (int)$profile['tweet_count'] ?></span>
        </div>
    </div>
    <div class="profile-action">
        <?php if ($isOwn): ?>
            <a href="/settings" class="secondary-button">Edit Profile</a>
        <?php elseif ($currentUser): ?>
            <form action="/follow/<?= Helpers::h($profile['username']) ?>" method="post" data-follow-form>
                <?= Helpers::csrfField() ?>
                <button type="submit" class="primary-button"><?= $followPending ? 'Requested' : ($isFollowing ? 'Unfollow' : 'Follow') ?></button>
            </form>
            <?php if ($canMessage): ?>
                <a href="/direct_messages?user=<?= Helpers::h($profile['username']) ?>" class="secondary-button">Message</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<nav class="tabs">
    <a class="<?= $tab === 'tweets' ? 'active' : '' ?>" href="/<?= Helpers::h($profile['username']) ?>">Tweets</a>
    <a class="<?= $tab === 'replies' ? 'active' : '' ?>" href="/<?= Helpers::h($profile['username']) ?>?tab=replies">Replies</a>
    <a class="<?= $tab === 'favorites' ? 'active' : '' ?>" href="/<?= Helpers::h($profile['username']) ?>?tab=favorites">Favorites</a>
    <?php if ($canViewAnalytics): ?>
        <a class="<?= $tab === 'analytics' ? 'active' : '' ?>" href="/<?= Helpers::h($profile['username']) ?>?tab=analytics">Analytics</a>
    <?php endif; ?>
</nav>

<?php if ($tab === 'analytics'): ?>
    <?= Helpers::renderPartial('profile/analytics', ['analytics' => $analytics]) ?>
<?php else: ?>
    <div class="timeline" id="timeline"<?= $canSeeTweets ? ' data-realtime-feed="/api/timeline?scope=profile&amp;username=' . Helpers::h($profile['username']) . '&amp;tab=' . Helpers::h($tab) . '" data-realtime-insert="prepend"' : '' ?>>
        <?php if (!$canSeeTweets): ?>
            <div class="empty-state">This account is private. Follow requests must be approved before posts are visible.</div>
        <?php elseif ($tweets === []): ?>
            <div class="empty-state">No tweets to show.</div>
        <?php else: ?>
            <?php foreach ($tweets as $tweet): ?>
                <?= Helpers::renderPartial('partials/tweet_row', ['tweet' => $tweet, 'currentUser' => $currentUser]) ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
