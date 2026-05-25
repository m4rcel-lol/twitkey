<?php
use Twitkey\Core\Helpers;
use Twitkey\Core\Session;
use Twitkey\Core\Database;
use Twitkey\Models\Notification;

$appName = Helpers::env('APP_NAME', 'Twitkey');
$unread = $currentUser ? Notification::unreadCount((int)$currentUser['id']) : 0;
$notificationsLabel = $unread > 0 ? '(' . ($unread > 99 ? '99+' : (string)$unread) . ') Notifications' : 'Notifications';
$unreadMessages = $currentUser ? (int)(Database::instance()->one('SELECT COUNT(*) AS count FROM direct_messages WHERE recipient_id = :id AND is_read = 0', ['id' => (int)$currentUser['id']])['count'] ?? 0) : 0;
$messagesLabel = $unreadMessages > 0 ? '(' . ($unreadMessages > 99 ? '99+' : (string)$unreadMessages) . ') Direct Messages' : 'Direct Messages';
$siteAlert = Database::instance()->one('SELECT id, message, updated_at FROM site_alerts WHERE is_active = 1 AND message <> :empty ORDER BY updated_at DESC, id DESC LIMIT 1', ['empty' => '']);
$theme = (string)($currentUser['theme_choice'] ?? $currentUser['theme'] ?? 'classic');
if (!array_key_exists($theme, Helpers::themes())) {
    $theme = 'classic';
}
$customThemeCss = $currentUser && $theme === 'custom' ? Helpers::customThemeCssForOutput((string)($currentUser['custom_theme_css'] ?? '')) : '';
$bodyClasses = ['theme-' . $theme];
if (!empty($adminLayout)) {
    $bodyClasses[] = 'admin-mode';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= Helpers::h(Helpers::csrfToken()) ?>">
    <title><?= Helpers::h($title ?? $appName) ?> / <?= Helpers::h($appName) ?></title>
    <link rel="stylesheet" href="/css/twitkey.css">
    <?php if ($customThemeCss !== ''): ?>
        <style id="twitkey-custom-theme"><?= $customThemeCss ?></style>
    <?php endif; ?>
</head>
<body class="<?= Helpers::h(implode(' ', $bodyClasses)) ?>">
<div class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="/">
            <span class="brand-word">twitkey</span>
            <img src="/img/logo.svg" alt="" class="brand-bird">
        </a>
        <div class="topnav">
            <?php if ($currentUser): ?>
                <a href="/">Home</a> |
                <a href="/users">Users</a> |
                <a href="/<?= Helpers::h($currentUser['username']) ?>">Profile</a> |
                <a href="/replies">@Replies</a> |
                <a href="/notifications" data-notifications-link data-count="<?= (int)$unread ?>"><?= Helpers::h($notificationsLabel) ?></a> |
                <a href="/direct_messages" data-messages-link data-count="<?= (int)$unreadMessages ?>"><?= Helpers::h($messagesLabel) ?></a> |
                <a href="/settings">Settings</a> |
                <?php if ((int)$currentUser['is_admin'] === 1): ?>
                    <a href="/admin">Admin</a> |
                <?php endif; ?>
                <a href="/help">Help</a> |
                <a href="/logout">Sign out</a>
            <?php else: ?>
                <a href="/users">Users</a> |
                <a href="/login">Sign in</a> |
                <a href="/register">Join now</a> |
                <a href="/help">Help</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="site-alert<?= $siteAlert ? '' : ' hidden' ?>" data-site-alert data-alert-id="<?= $siteAlert ? (int)$siteAlert['id'] : 0 ?>">
    <div class="site-alert-inner" data-site-alert-message><?= $siteAlert ? Helpers::h($siteAlert['message']) : '' ?></div>
</div>

<?php if (empty($adminLayout)): ?>
<div class="subheader">
    <div class="subheader-inner">
        <?php if ($currentUser): ?>
            <span class="avatar-frame compose-avatar-frame">
                <img src="<?= Helpers::avatarUrl($currentUser) ?>" class="compose-avatar" alt="">
                <?= Helpers::adminAvatarBadge($currentUser) ?>
            </span>
            <form action="/tweet" method="post" enctype="multipart/form-data" class="compose-form" data-tweet-form>
                <?= Helpers::csrfField() ?>
                <input type="hidden" name="_post_id" value="<?= Helpers::h(bin2hex(random_bytes(16))) ?>" data-post-id>
                <label for="compose-body">What are you doing?</label>
                <textarea id="compose-body" name="body" maxlength="140" data-counter-target="#compose-count"></textarea>
                <div class="compose-tools">
                    <button type="button" title="Attach media" data-attachment-button><img src="/img/icon_attachment.svg" alt="">Attachment</button>
                    <button type="button" title="Add poll" data-panel-toggle="poll-panel"><img src="/img/icon_poll.svg" alt="">Poll</button>
                    <button type="button" title="Add location" data-panel-toggle="location-panel"><img src="/img/icon_location.svg" alt="">Location</button>
                    <button type="button" title="Schedule post" data-panel-toggle="schedule-panel"><img src="/img/icon_schedule.svg" alt="">Schedule</button>
                </div>
                <input type="file" name="attachments[]" accept="image/jpeg,image/png,image/gif,image/webp,audio/*,video/*,.mp3,.mp4,.mov,.m4a,.aac,.wav,.ogg,.flac,.aiff,.wma,.webm,.ogv,.avi,.wmv,.mkv" multiple class="hidden-file" data-attachment-input>
                <input type="hidden" name="location_lat" data-location-lat>
                <input type="hidden" name="location_lng" data-location-lng>
                <input type="hidden" name="location_label" data-location-label>
                <div class="compose-panel" data-compose-panel="poll-panel">
                    <input type="text" name="poll_question" maxlength="120" placeholder="Poll question">
                    <input type="text" name="poll_options[]" maxlength="80" placeholder="Option 1">
                    <input type="text" name="poll_options[]" maxlength="80" placeholder="Option 2">
                    <input type="text" name="poll_options[]" maxlength="80" placeholder="Option 3 optional">
                    <input type="text" name="poll_options[]" maxlength="80" placeholder="Option 4 optional">
                </div>
                <div class="compose-panel" data-compose-panel="location-panel">
                    <div class="tool-search">
                        <input type="text" placeholder="Search a place" data-location-query>
                        <button type="button" data-location-search>Search</button>
                    </div>
                    <div class="location-results" data-location-results></div>
                    <div class="map-picker" data-map-picker title="Click to drop a pin">
                        <span class="map-pin" data-map-pin></span>
                    </div>
                    <div class="selected-location" data-selected-location>No location selected.</div>
                    <div class="tool-hint">Search data by <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>.</div>
                </div>
                <div class="compose-panel" data-compose-panel="schedule-panel">
                    <input type="datetime-local" name="scheduled_at">
                    <div class="tool-hint">Scheduled posts appear in timelines when this time is reached.</div>
                </div>
                <div class="compose-bottom">
                    <span id="compose-count" class="char-counter">140</span>
                    <button type="submit">update</button>
                </div>
            </form>
            <form action="/search" method="get" class="header-search">
                <input type="text" name="q" placeholder="Search" value="<?= Helpers::h($_GET['q'] ?? '') ?>">
            </form>
        <?php else: ?>
            <div class="guest-cta">
                <strong>What are you doing?</strong>
                <span>Join Twitkey to post short updates and follow public conversations.</span>
                <a href="/register">Join today!</a>
                <a href="/login">Sign in</a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="page-wrap<?= !empty($hideSidebar) ? ' page-wrap-centered' : '' ?><?= !empty($wideLayout) ? ' page-wrap-wide' : '' ?>">
    <main class="main-col<?= !empty($hideSidebar) ? ' main-col-centered' : '' ?><?= !empty($wideLayout) ? ' main-col-wide' : '' ?>">
        <?php foreach (Session::consumeFlash() as $flash): ?>
            <div class="flash flash-<?= Helpers::h($flash['type']) ?>"><?= Helpers::h($flash['message']) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
    </main>

    <?php if (empty($hideSidebar) && empty($wideLayout)): ?>
        <aside class="sidebar">
            <?php if ($currentUser): ?>
                <section class="side-box profile-mini">
                    <div class="side-header">@<?= Helpers::h($currentUser['username']) ?> <a href="/settings">edit profile</a></div>
                    <div class="profile-mini-body">
                        <span class="avatar-frame profile-mini-avatar-frame">
                            <img src="<?= Helpers::avatarUrl($currentUser) ?>" alt="" class="profile-mini-avatar">
                            <?= Helpers::adminAvatarBadge($currentUser) ?>
                        </span>
                        <p><?= Helpers::h($currentUser['bio'] ?: 'No bio yet.') ?></p>
                        <div class="mini-stats">
                            <a href="/<?= Helpers::h($currentUser['username']) ?>/following">Following: <?= (int)$currentUser['following_count'] ?></a>
                            <a href="/<?= Helpers::h($currentUser['username']) ?>/followers">Followers: <?= (int)$currentUser['follower_count'] ?></a>
                        </div>
                        <?php if ($unread > 0): ?>
                            <a href="/notifications" class="unread-link"><?= $unread ?> new notification<?= $unread === 1 ? '' : 's' ?></a>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="side-box">
                <div class="side-header">Trending Topics</div>
                <?php if (($sidebar['trends'] ?? []) === []): ?>
                    <div class="side-empty">No trends yet.</div>
                <?php else: ?>
                    <ol class="trend-list">
                        <?php foreach ($sidebar['trends'] as $trend): ?>
                            <li><a href="/search?q=%23<?= rawurlencode($trend['tag']) ?>">#<?= Helpers::h($trend['tag']) ?></a> <span>(<?= number_format((int)$trend['count']) ?> tweets)</span></li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>

            <section class="side-box">
                <div class="side-header">Who to Follow</div>
                <?php if (($sidebar['suggestions'] ?? []) === []): ?>
                    <div class="side-empty">No suggestions yet.</div>
                <?php else: ?>
                    <?php foreach ($sidebar['suggestions'] as $suggestion): ?>
                        <div class="suggestion">
                            <span class="avatar-frame suggestion-avatar-frame">
                                <img src="<?= Helpers::avatarUrl($suggestion) ?>" alt="" class="suggestion-avatar">
                                <?= Helpers::adminAvatarBadge($suggestion) ?>
                            </span>
                            <div class="suggestion-body">
                                <?= Helpers::renderUserName($suggestion) ?>
                                <div class="suggestion-bio"><?= Helpers::h(Helpers::truncate((string)$suggestion['bio'], 40)) ?></div>
                                <?php if ($currentUser): ?>
                                    <form action="/follow/<?= Helpers::h($suggestion['username']) ?>" method="post" data-follow-form>
                                        <?= Helpers::csrfField() ?>
                                        <button class="mini-button" type="submit">Follow</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <section class="side-footer">
                <a href="/public">Public Timeline</a> ·
                <a href="/users">Users</a> ·
                <a href="/search">Search</a> ·
                <a href="/notes/pending">Community Notes</a> ·
                <a href="/help">Help</a> ·
                <a href="/privacy">Privacy</a> ·
                <a href="/terms">Terms</a>
            </section>
        </aside>
    <?php endif; ?>
</div>

<?php if (empty($adminLayout)): ?>
<footer class="site-footer">
    <div>
        <strong><?= Helpers::h($appName) ?></strong>
        <a href="/public">Public Timeline</a>
        <a href="/users">Users</a>
        <a href="/help">Help</a>
        <a href="/privacy">Privacy Policy</a>
        <a href="/terms">Terms of Service</a>
    </div>
</footer>
<?php endif; ?>

<div class="media-lightbox" data-media-lightbox hidden>
    <button type="button" data-lightbox-close aria-label="Close media">×</button>
    <div data-lightbox-content></div>
</div>

<script src="/js/twitkey.js"></script>
</body>
</html>
