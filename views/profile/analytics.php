<?php use Twitkey\Core\Helpers; ?>
<?php $summary = $analytics['summary'] ?? []; $recent = $analytics['recent'] ?? []; ?>
<section class="profile-analytics">
    <div class="analytics-grid">
        <div><span>Posts</span><strong><?= number_format((int)($summary['posts'] ?? 0)) ?></strong></div>
        <div><span>Favorites</span><strong><?= number_format((int)($summary['favorites'] ?? 0)) ?></strong></div>
        <div><span>Reposts</span><strong><?= number_format((int)($summary['reposts'] ?? 0)) ?></strong></div>
        <div><span>Replies</span><strong><?= number_format((int)($summary['replies'] ?? 0)) ?></strong></div>
        <div><span>Poll votes</span><strong><?= number_format((int)($summary['poll_votes'] ?? 0)) ?></strong></div>
    </div>

    <h2>Recent interactions</h2>
    <?php if ($recent === []): ?>
        <div class="empty-state">No interactions yet.</div>
    <?php else: ?>
        <?php foreach ($recent as $interaction): ?>
            <?php
            $actor = [
                'id' => $interaction['actor_id'],
                'username' => $interaction['username'],
                'display_name' => $interaction['display_name'],
                'avatar' => $interaction['avatar'],
                'is_admin' => $interaction['is_admin'],
                'is_system' => $interaction['is_system'],
                'is_verified' => $interaction['is_verified'],
                'is_private' => $interaction['is_private'],
                'verified_type' => $interaction['verified_type'],
            ];
            $verb = match ((string)$interaction['type']) {
                'favorite' => 'favorited',
                'repost' => 'reposted',
                'reply' => 'replied to',
                'poll_vote' => 'voted on',
                default => 'interacted with',
            };
            ?>
            <article class="analytics-row">
                <span class="avatar-frame small-avatar-frame">
                    <img src="<?= Helpers::avatarUrl($actor) ?>" class="small-avatar" alt="">
                    <?= Helpers::adminAvatarBadge($actor) ?>
                </span>
                <div>
                    <?= Helpers::renderUserName($actor) ?>
                    <span class="muted"><?= Helpers::h($verb) ?> <a href="/tweet/<?= (int)$interaction['tweet_id'] ?>">your post</a> · <?= Helpers::timeAgo((string)$interaction['created_at']) ?></span>
                    <p><?= Helpers::h(Helpers::truncate((string)$interaction['tweet_body'], 120)) ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
