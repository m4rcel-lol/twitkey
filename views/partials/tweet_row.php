<?php
use Twitkey\Core\Auth;
use Twitkey\Core\Helpers;
use Twitkey\Models\Tweet;

$currentUser = $currentUser ?? Auth::user();
$author = [
    'id' => $tweet['user_id'] ?? $tweet['id'] ?? 0,
    'username' => $tweet['username'] ?? '',
    'display_name' => $tweet['display_name'] ?? $tweet['username'] ?? '',
    'avatar' => $tweet['avatar'] ?? null,
    'is_admin' => $tweet['is_admin'] ?? 0,
    'is_system' => $tweet['is_system'] ?? 0,
    'is_verified' => $tweet['is_verified'] ?? 0,
    'is_private' => $tweet['is_private'] ?? 0,
    'verified_type' => $tweet['verified_type'] ?? null,
];
$deleted = (int)($tweet['is_deleted'] ?? 0) === 1;
$tweetId = (int)($tweet['id'] ?? 0);
$favorited = $currentUser ? Tweet::isFavorited((int)$currentUser['id'], $tweetId) : false;
$retweeted = $currentUser ? Tweet::isRetweeted((int)$currentUser['id'], $tweetId) : false;
$isOwnTweet = $currentUser && (int)$currentUser['id'] === (int)$tweet['user_id'];
?>
<article class="tweet-row<?= $deleted ? ' tweet-row-deleted' : '' ?>" id="tweet-<?= $tweetId ?>" data-tweet-id="<?= $tweetId ?>">
    <a href="/<?= Helpers::h($author['username']) ?>" class="avatar-frame tweet-avatar-frame">
        <img src="<?= Helpers::avatarUrl($author) ?>" class="tweet-avatar" alt="">
        <?= Helpers::adminAvatarBadge($author) ?>
    </a>
    <div class="tweet-content">
        <?php if ($deleted): ?>
            <span class="tweet-body deleted-text">[Tweet deleted]</span>
        <?php else: ?>
            <?php if (!empty($tweet['reposted_by_username'])): ?>
                <div class="repost-context">
                    <?= $currentUser && (int)($tweet['reposted_by_id'] ?? 0) === (int)$currentUser['id'] ? 'You' : Helpers::h($tweet['reposted_by_display_name'] ?: $tweet['reposted_by_username']) ?> reposted
                </div>
            <?php endif; ?>
            <strong><?= Helpers::renderUserName($author) ?></strong>
            <?php if (!empty($tweet['reply_to_id']) && !empty($tweet['reply_parent_username'])): ?>
                <div class="reply-context">
                    Replying to <a href="/<?= Helpers::h($tweet['reply_parent_username']) ?>">@<?= Helpers::h($tweet['reply_parent_username']) ?></a>
                    <?php if ((int)($tweet['reply_parent_deleted'] ?? 0) === 1): ?>
                        <span>[Tweet deleted]</span>
                    <?php elseif (!empty($tweet['reply_parent_body'])): ?>
                        <span><?= Helpers::h(Helpers::truncate((string)$tweet['reply_parent_body'], 80)) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ((string)$tweet['body'] !== ''): ?>
                <span class="tweet-body"><?= Helpers::renderTweetBody((string)$tweet['body']) ?></span>
            <?php endif; ?>
            <?= Helpers::renderEmbeds((string)$tweet['body']) ?>
            <?php if (!empty($tweet['approved_note_body'])): ?>
                <a class="note-preview" href="/tweet/<?= $tweetId ?>" title="<?= Helpers::h(Helpers::truncate((string)$tweet['approved_note_body'], 120)) ?>">📋</a>
            <?php endif; ?>
            <?php if (!empty($tweet['gif_url'])): ?>
                <div class="tweet-gif">
                    <a href="/gif_proxy?url=<?= rawurlencode((string)$tweet['gif_url']) ?>" data-media-lightbox-item data-media-type="image">
                        <img src="/gif_proxy?url=<?= rawurlencode((string)$tweet['gif_url']) ?>" alt="GIF attachment" loading="lazy">
                    </a>
                </div>
            <?php endif; ?>
            <?php if (!empty($tweet['media'])): ?>
                <div class="tweet-media media-count-<?= count($tweet['media']) ?>">
                    <?php foreach ($tweet['media'] as $media): ?>
                        <?php
                        $mediaUrl = '/media/' . rawurlencode((string)$media['file_name']);
                        $mime = (string)($media['mime_type'] ?? '');
                        ?>
                        <?php if (str_starts_with($mime, 'video/')): ?>
                            <a href="<?= Helpers::h($mediaUrl) ?>" data-media-lightbox-item data-media-type="video">
                                <video src="<?= Helpers::h($mediaUrl) ?>" preload="metadata" muted playsinline></video>
                            </a>
                        <?php elseif (str_starts_with($mime, 'audio/')): ?>
                            <div class="tweet-audio">
                                <audio src="<?= Helpers::h($mediaUrl) ?>" controls preload="metadata"></audio>
                            </div>
                        <?php else: ?>
                            <a href="<?= Helpers::h($mediaUrl) ?>" data-media-lightbox-item data-media-type="image">
                                <img src="<?= Helpers::h($mediaUrl) ?>" alt="Tweet attachment" loading="lazy">
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($tweet['poll'])): ?>
                <?php $poll = $tweet['poll']; $totalVotes = max(0, (int)$poll['total_votes']); ?>
                <div class="tweet-poll">
                    <div class="poll-question"><?= Helpers::h($poll['question']) ?></div>
                    <?php foreach ($poll['options'] as $option): ?>
                        <?php $votes = (int)$option['vote_count']; $percent = $totalVotes > 0 ? (int)round(($votes / $totalVotes) * 100) : 0; ?>
                        <form action="/tweet/<?= $tweetId ?>/poll/<?= (int)$option['id'] ?>" method="post" class="poll-option-form" data-poll-form>
                            <?= Helpers::csrfField() ?>
                            <button type="submit" class="poll-option"<?= !$currentUser ? ' disabled' : '' ?>>
                                <span class="poll-fill" style="width: <?= $percent ?>%"></span>
                                <span class="poll-text"><?= Helpers::h($option['body']) ?></span>
                                <span class="poll-percent"><?= $percent ?>%</span>
                            </button>
                        </form>
                    <?php endforeach; ?>
                    <div class="poll-meta"><?= number_format($totalVotes) ?> vote<?= $totalVotes === 1 ? '' : 's' ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($tweet['location_label']) && $tweet['location_lat'] !== null && $tweet['location_lng'] !== null): ?>
                <div class="tweet-location">
                    <img src="/img/icon_location.svg" alt="">
                    <a href="https://www.openstreetmap.org/?mlat=<?= rawurlencode((string)$tweet['location_lat']) ?>&mlon=<?= rawurlencode((string)$tweet['location_lng']) ?>#map=12/<?= rawurlencode((string)$tweet['location_lat']) ?>/<?= rawurlencode((string)$tweet['location_lng']) ?>" target="_blank" rel="noopener">
                        <?= Helpers::h($tweet['location_label']) ?>
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="tweet-meta">
            <a href="/tweet/<?= $tweetId ?>"><?= Helpers::timeAgo((string)$tweet['created_at']) ?></a>
            <?php if (!$deleted): ?>
                · <a href="/tweet/<?= $tweetId ?>" class="tweet-action" data-reply-toggle>reply</a>
                <?php if ($currentUser): ?>
                    · <form action="/tweet/<?= $tweetId ?>/retweet" method="post" class="inline-form" data-retweet-form>
                        <?= Helpers::csrfField() ?><button type="submit" class="tweet-action<?= $retweeted ? ' is-retweeted' : '' ?>"<?= $retweeted || $isOwnTweet ? ' disabled' : '' ?>><?= $retweeted ? 'reposted' : 'repost' ?></button>
                    </form>
                    · <form action="/tweet/<?= $tweetId ?>/favorite" method="post" class="inline-form" data-favorite-form>
                        <?= Helpers::csrfField() ?><button type="submit" class="tweet-action<?= $favorited ? ' is-favorited' : '' ?>"><?= $favorited ? 'favorited' : 'favorite' ?></button>
                    </form>
                <?php endif; ?>
                · <span><span class="reply-count"><?= (int)$tweet['reply_count'] ?></span> replies</span>
                · <span><span class="retweet-count"><?= (int)$tweet['retweet_count'] ?></span> retweets</span>
                · <span><span class="favorite-count"><?= (int)$tweet['favorite_count'] ?></span> favorites</span>
                <?php if ($currentUser && ((int)$currentUser['id'] === (int)$tweet['user_id'] || (int)$currentUser['is_admin'] === 1)): ?>
                    · <button class="tweet-action delete-action" data-delete-url="/tweet/<?= $tweetId ?>">delete</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($currentUser && !$deleted): ?>
            <form action="/tweet/<?= $tweetId ?>/reply" method="post" enctype="multipart/form-data" class="inline-reply" data-reply-form>
                <?= Helpers::csrfField() ?>
                <input type="hidden" name="_post_id" value="<?= Helpers::h(bin2hex(random_bytes(16))) ?>" data-post-id>
                <textarea name="body" maxlength="140" placeholder="Reply to @<?= Helpers::h($author['username']) ?>"></textarea>
                <input type="file" name="attachments[]" accept="image/jpeg,image/png,image/gif,image/webp,audio/*,video/*,.mp3,.mp4,.mov,.m4a,.aac,.wav,.ogg,.flac,.aiff,.wma,.webm,.ogv,.avi,.wmv,.mkv" multiple class="hidden-file" data-attachment-input>
                <button type="button" class="reply-attach" data-attachment-button>Attach media</button>
                <button type="submit">reply</button>
            </form>
        <?php endif; ?>
    </div>
</article>
