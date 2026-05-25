<?php use Twitkey\Core\Helpers; ?>
<div class="content-header">
    <h1>Pending Community Notes</h1>
</div>
<?php if ($notes === []): ?>
    <div class="empty-state">No pending notes.</div>
<?php else: ?>
    <?php foreach ($notes as $note): ?>
        <section class="pending-note">
            <div class="muted">Tweet by @<?= Helpers::h($note['tweet_username']) ?>: <?= Helpers::h(Helpers::truncate((string)$note['tweet_body'], 120)) ?></div>
            <p><?= Helpers::h($note['body']) ?></p>
            <div class="muted">submitted by @<?= Helpers::h($note['author_username']) ?> · <?= (int)$note['helpful_votes'] ?> helpful · <?= (int)$note['unhelpful_votes'] ?> unhelpful</div>
            <?php if ($currentUser && (int)$currentUser['id'] === (int)$note['author_id']): ?>
                <div class="muted">You cannot vote on your own note.</div>
            <?php else: ?>
                <form action="/note/<?= (int)$note['id'] ?>/vote" method="post" class="button-row">
                    <?= Helpers::csrfField() ?>
                    <button type="submit" name="vote" value="helpful" class="mini-button">Helpful</button>
                    <button type="submit" name="vote" value="unhelpful" class="mini-button">Not Helpful</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
