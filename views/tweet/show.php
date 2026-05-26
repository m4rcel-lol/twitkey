<?php use Twitkey\Core\Helpers; ?>
<div class="content-header">
    <h1>Tweet</h1>
</div>
<div class="tweet-detail">
    <?= Helpers::renderPartial('partials/tweet_row', ['tweet' => $tweet, 'currentUser' => $currentUser]) ?>
</div>

<?php if ($note): ?>
    <section class="community-note<?= !empty($note['is_community_verified']) ? ' is-verified' : '' ?>" data-community-note>
        <div class="note-header">
            📋 Community Note
            <?php if (!empty($note['is_community_verified'])): ?>
                <span class="verified-label">✓ Verified by The Community</span>
            <?php endif; ?>
            <button type="button" data-note-toggle>▼ hide</button>
        </div>
        <div class="note-body">
            <p><?= Helpers::h($note['body']) ?></p>
            <div class="muted">written by @<?= Helpers::h($note['username']) ?></div>
            <div class="note-actions">
                <?php if ($currentUser): ?>
                    <form action="/note/<?= (int)$note['id'] ?>/vote" method="post" class="inline-form">
                        <?= Helpers::csrfField() ?><button type="submit" name="vote" value="helpful">✔ Helpful</button>
                    </form>
                    <form action="/note/<?= (int)$note['id'] ?>/vote" method="post" class="inline-form">
                        <?= Helpers::csrfField() ?><button type="submit" name="vote" value="unhelpful">✗ Not Helpful</button>
                    </form>
                    <form action="/note/<?= (int)$note['id'] ?>/vote" method="post" class="inline-form">
                        <?= Helpers::csrfField() ?><button type="submit" name="vote" value="flag">flag misleading</button>
                    </form>
                <?php endif; ?>
                <span><?= (int)$note['helpful_votes'] ?> people found this helpful</span>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($currentUser && $canNote): ?>
    <details class="note-form-wrap">
        <summary><?= (int)$currentUser['is_admin'] === 1 ? 'Add an approved Community Note' : 'Add a Community Note' ?></summary>
        <form action="/tweet/<?= (int)$tweet['id'] ?>/note" method="post" class="settings-form compact-form">
            <?= Helpers::csrfField() ?>
            <textarea name="body" maxlength="500" required></textarea>
            <button type="submit" class="primary-button">Submit note</button>
        </form>
    </details>
<?php endif; ?>

<div class="content-header secondary-heading">
    <h1>Replies</h1>
</div>
<div class="timeline" id="replies-timeline" data-realtime-feed="/api/timeline?scope=replies&amp;tweet_id=<?= (int)$tweet['id'] ?>" data-realtime-insert="append">
    <?php if ($replies === []): ?>
        <div class="empty-state">No replies yet.</div>
    <?php else: ?>
        <?php foreach ($replies as $reply): ?>
            <?= Helpers::renderPartial('partials/tweet_row', ['tweet' => $reply, 'currentUser' => $currentUser]) ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
