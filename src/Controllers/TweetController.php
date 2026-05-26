<?php
declare(strict_types=1);

namespace Twitkey\Controllers;

use Twitkey\Core\Auth;
use Twitkey\Core\Database;
use Twitkey\Core\Helpers;
use Twitkey\Core\Session;
use Twitkey\Models\CommunityNote;
use Twitkey\Models\Tweet;
use Twitkey\Models\TwoFactor;

final class TweetController
{
    /**
     * Create a tweet.
     */
    public function create(): void
    {
        Helpers::verifyCsrf();
        $user = Auth::requireActiveUser();
        $existing = $this->existingTweetForPostId();
        if ($existing) {
            $this->respondWithTweet($existing, $user);
        }
        $this->rateLimit((int)$user['id']);
        $media = [];
        try {
            $media = $this->handleTweetMediaUploads();
            $metadata = $this->tweetMetadata($media);
            $tweet = Tweet::create((int)$user['id'], (string)($_POST['body'] ?? ''), null, null, $metadata);
            $this->rememberPostId((int)$tweet['id']);
            if (Helpers::wantsJson()) {
                $html = Helpers::renderPartial('partials/tweet_row', ['tweet' => $tweet, 'currentUser' => $user]);
                Helpers::json(['ok' => true, 'html' => $html, 'scheduled' => !empty($metadata['scheduled_at']) && strtotime((string)$metadata['scheduled_at']) > time()]);
            }
            Helpers::redirect('/');
        } catch (\Throwable $e) {
            $this->deleteStoredMedia($media);
            $this->fail($e->getMessage(), '/');
        }
    }

    /**
     * Show a tweet and its direct replies.
     */
    public function show(string $id): void
    {
        $tweet = Tweet::findWithUser((int)$id, true);
        if (!$tweet) {
            http_response_code(404);
            Helpers::render('errors/404', ['title' => 'Tweet Not Found'], true);
            return;
        }
        $currentUser = Auth::user();
        $scheduledForFuture = !empty($tweet['scheduled_at']) && strtotime((string)$tweet['scheduled_at']) > time();
        $canSeeScheduled = $currentUser && ((int)$currentUser['id'] === (int)$tweet['user_id'] || Auth::isAdmin());
        if (((int)$tweet['is_deleted'] === 1 && !Auth::isAdmin()) || ($scheduledForFuture && !$canSeeScheduled) || !Tweet::canBeViewedBy($tweet, $currentUser)) {
            http_response_code(404);
            Helpers::render('errors/404', ['title' => 'Tweet Not Found'], true);
            return;
        }
        $note = CommunityNote::approvedForTweet((int)$tweet['id']);
        if ($note) {
            $note['is_community_verified'] = CommunityNote::isCommunityVerified((int)$note['id']);
        }
        $replies = array_values(array_filter(
            Tweet::repliesTo((int)$tweet['id'], true),
            static fn(array $reply): bool => Tweet::canBeViewedBy($reply, $currentUser)
        ));
        Helpers::render('tweet/show', [
            'title' => 'Tweet by @' . $tweet['username'],
            'tweet' => $tweet,
            'replies' => $replies,
            'note' => $note,
            'canNote' => Auth::isAdmin() || Helpers::eligibleForNotes($currentUser),
        ]);
    }

    /**
     * Reply to a tweet.
     */
    public function reply(string $id): void
    {
        Helpers::verifyCsrf();
        $user = Auth::requireActiveUser();
        $existing = $this->existingTweetForPostId();
        if ($existing) {
            $this->respondWithTweet($existing, $user);
        }
        $this->rateLimit((int)$user['id']);
        try {
            $parent = Tweet::findWithUser((int)$id);
            if (!$parent || !Tweet::canBeViewedBy($parent, $user)) {
                throw new \RuntimeException('Forbidden.');
            }
            $media = $this->handleTweetMediaUploads();
            $metadata = ['media' => $media];
            $tweet = Tweet::create((int)$user['id'], (string)($_POST['body'] ?? ''), (int)$id, null, $metadata);
            $this->rememberPostId((int)$tweet['id']);
            if (Helpers::wantsJson()) {
                $html = Helpers::renderPartial('partials/tweet_row', ['tweet' => $tweet, 'currentUser' => $user]);
                Helpers::json(['ok' => true, 'html' => $html]);
            }
            Helpers::redirect('/tweet/' . (int)$id);
        } catch (\Throwable $e) {
            if (isset($media) && is_array($media)) {
                $this->deleteStoredMedia($media);
            }
            $this->fail($e->getMessage(), '/tweet/' . (int)$id);
        }
    }

    /**
     * Retweet a tweet.
     */
    public function retweet(string $id): void
    {
        Helpers::verifyCsrf();
        $user = Auth::requireActiveUser();
        try {
            $result = Tweet::retweet((int)$user['id'], (int)$id);
            if (Helpers::wantsJson()) {
                Helpers::json(['ok' => true] + $result);
            }
            Helpers::redirect($_SERVER['HTTP_REFERER'] ?? '/');
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), $_SERVER['HTTP_REFERER'] ?? '/');
        }
    }

    /**
     * Toggle favorite state.
     */
    public function favorite(string $id): void
    {
        Helpers::verifyCsrf();
        $user = Auth::requireActiveUser();
        try {
            $result = Tweet::toggleFavorite((int)$user['id'], (int)$id);
            if (Helpers::wantsJson()) {
                Helpers::json(['ok' => true] + $result);
            }
            Helpers::redirect($_SERVER['HTTP_REFERER'] ?? '/');
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), $_SERVER['HTTP_REFERER'] ?? '/');
        }
    }

    /**
     * Vote on a tweet poll.
     */
    public function votePoll(string $id, string $option_id): void
    {
        Helpers::verifyCsrf();
        $user = Auth::requireActiveUser();
        try {
            $tweet = Tweet::findWithUser((int)$id);
            if (!$tweet || !Tweet::canBeViewedBy($tweet, $user)) {
                throw new \RuntimeException('Forbidden.');
            }
            Tweet::votePoll((int)$id, (int)$option_id, (int)$user['id']);
            if (Helpers::wantsJson()) {
                $tweet = Tweet::findWithUser((int)$id, true);
                $html = $tweet ? Helpers::renderPartial('partials/tweet_row', ['tweet' => $tweet, 'currentUser' => $user]) : '';
                Helpers::json(['ok' => true, 'html' => $html]);
            }
            Helpers::redirect($_SERVER['HTTP_REFERER'] ?? '/tweet/' . (int)$id);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), $_SERVER['HTTP_REFERER'] ?? '/tweet/' . (int)$id);
        }
    }

    /**
     * Soft delete a tweet.
     */
    public function delete(string $id): void
    {
        Helpers::verifyCsrf();
        $user = Auth::requireLogin();
        try {
            if (Auth::isAdmin()) {
                $tweet = Tweet::findWithUser((int)$id, true);
                if ($tweet && (int)$tweet['user_id'] !== (int)$user['id']) {
                    $this->requireFreshAdminAction();
                }
            }
            Tweet::delete((int)$id, (int)$user['id'], Auth::isAdmin());
            if (Helpers::wantsJson()) {
                Helpers::json(['ok' => true]);
            }
            Helpers::redirect($_SERVER['HTTP_REFERER'] ?? '/');
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), $_SERVER['HTTP_REFERER'] ?? '/');
        }
    }

    /**
     * Show pending community notes.
     */
    public function pendingNotes(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::isAdmin() && !Helpers::eligibleForNotes($user)) {
            http_response_code(403);
            Helpers::render('errors/403', ['title' => 'Not Eligible'], true);
            return;
        }
        Helpers::render('tweet/pending_notes', [
            'title' => 'Pending Notes',
            'notes' => CommunityNote::pending(Helpers::page()),
            'page' => Helpers::page(),
        ]);
    }

    /**
     * Add a community note to a tweet.
     */
    public function addNote(string $id): void
    {
        Helpers::verifyCsrf();
        $user = Auth::requireActiveUser();
        if (Auth::isAdmin()) {
            $this->requireFreshAdminAction();
            try {
                $bot = \Twitkey\Models\User::communityNotesBot();
                CommunityNote::addApproved((int)$id, (int)$bot['id'], (string)($_POST['body'] ?? ''), (int)$user['id']);
                Session::flash('success', 'Community Note published as @CommunityNotes.');
                Helpers::redirect('/tweet/' . (int)$id);
            } catch (\Throwable $e) {
                $this->fail($e->getMessage(), '/tweet/' . (int)$id);
            }
        }
        if (!Helpers::eligibleForNotes($user)) {
            $this->fail('You are not eligible to add Community Notes yet.', '/tweet/' . (int)$id);
        }
        try {
            CommunityNote::add((int)$id, (int)$user['id'], (string)($_POST['body'] ?? ''));
            Session::flash('success', 'Community Note submitted for review.');
            Helpers::redirect('/tweet/' . (int)$id);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), '/tweet/' . (int)$id);
        }
    }

    /**
     * Vote on or flag a community note.
     */
    public function voteNote(string $id): void
    {
        Helpers::verifyCsrf();
        $user = Auth::requireActiveUser();
        $vote = (string)($_POST['vote'] ?? '');
        try {
            if ($vote === 'flag') {
                CommunityNote::flag((int)$id, (int)$user['id'], (string)($_POST['reason'] ?? ''));
                Session::flash('success', 'Note flagged for admin review.');
            } else {
                if (!Auth::isAdmin() && !Helpers::eligibleForNotes($user)) {
                    throw new \RuntimeException('You are not eligible to vote on Community Notes yet.');
                }
                CommunityNote::vote((int)$id, (int)$user['id'], $vote);
                Session::flash('success', 'Your note vote was recorded.');
            }
            Helpers::redirect($_SERVER['HTTP_REFERER'] ?? '/notes/pending');
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), $_SERVER['HTTP_REFERER'] ?? '/notes/pending');
        }
    }

    /**
     * Enforce 30 tweets per rolling hour in session.
     */
    private function rateLimit(int $userId): void
    {
        $now = time();
        $key = 'tweet_rate_' . $userId;
        $times = array_filter($_SESSION[$key] ?? [], static fn(int $t): bool => $t > $now - 3600);
        if (count($times) >= 30) {
            throw new \RuntimeException('You have reached the limit of 30 tweets per hour.');
        }
        $times[] = $now;
        $_SESSION[$key] = $times;
    }

    /**
     * Return an existing tweet when the same form post id is replayed.
     *
     * @return array<string, mixed>|null
     */
    private function existingTweetForPostId(): ?array
    {
        $postId = $this->postId();
        if ($postId === '') {
            return null;
        }
        $tweetId = (int)($_SESSION['post_idempotency'][$postId] ?? 0);
        return $tweetId > 0 ? Tweet::findWithUser($tweetId, true) : null;
    }

    /**
     * Remember a successful form post id for duplicate-submit protection.
     */
    private function rememberPostId(int $tweetId): void
    {
        $postId = $this->postId();
        if ($postId === '') {
            return;
        }
        $_SESSION['post_idempotency'] = $_SESSION['post_idempotency'] ?? [];
        $_SESSION['post_idempotency'][$postId] = $tweetId;
        if (count($_SESSION['post_idempotency']) > 50) {
            $_SESSION['post_idempotency'] = array_slice($_SESSION['post_idempotency'], -50, null, true);
        }
    }

    /**
     * Return the current idempotency key if it has an expected shape.
     */
    private function postId(): string
    {
        $postId = (string)($_POST['_post_id'] ?? '');
        return preg_match('/^[A-Za-z0-9-]{16,80}$/', $postId) === 1 ? $postId : '';
    }

    /**
     * Return a previously created tweet for a duplicate form submission.
     *
     * @param array<string, mixed> $tweet
     * @param array<string, mixed> $user
     */
    private function respondWithTweet(array $tweet, array $user): never
    {
        if (Helpers::wantsJson()) {
            $html = Helpers::renderPartial('partials/tweet_row', ['tweet' => $tweet, 'currentUser' => $user]);
            Helpers::json(['ok' => true, 'html' => $html, 'duplicate' => true]);
        }
        Helpers::redirect($_SERVER['HTTP_REFERER'] ?? '/');
    }

    /**
     * Build optional tweet metadata from the compose form.
     *
     * @param array<int, array{file_name:string,mime_type:string}> $media
     * @return array<string, mixed>
     */
    private function tweetMetadata(array $media): array
    {
        $metadata = ['media' => $media];

        $gifUrl = trim((string)($_POST['gif_url'] ?? ''));
        if ($gifUrl !== '') {
            $parts = parse_url($gifUrl);
            if (!filter_var($gifUrl, FILTER_VALIDATE_URL) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
                throw new \InvalidArgumentException('GIF URL must be a valid HTTPS URL.');
            }
            $metadata['gif_url'] = $gifUrl;
        }

        $lat = trim((string)($_POST['location_lat'] ?? ''));
        $lng = trim((string)($_POST['location_lng'] ?? ''));
        $label = trim((string)($_POST['location_label'] ?? ''));
        if ($lat !== '' || $lng !== '' || $label !== '') {
            if (!is_numeric($lat) || !is_numeric($lng)) {
                throw new \InvalidArgumentException('Location requires a valid latitude and longitude.');
            }
            $latitude = (float)$lat;
            $longitude = (float)$lng;
            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                throw new \InvalidArgumentException('Location is outside valid coordinates.');
            }
            $metadata['location_lat'] = $latitude;
            $metadata['location_lng'] = $longitude;
            $metadata['location_label'] = Helpers::mbLimit($label !== '' ? $label : sprintf('%.5f, %.5f', $latitude, $longitude), 160);
        }

        $scheduled = trim((string)($_POST['scheduled_at'] ?? ''));
        if ($scheduled !== '') {
            $time = strtotime($scheduled);
            if ($time === false) {
                throw new \InvalidArgumentException('Scheduled time is invalid.');
            }
            if ($time < time() - 60) {
                throw new \InvalidArgumentException('Scheduled time must be in the future.');
            }
            $metadata['scheduled_at'] = date('Y-m-d H:i:s', $time);
        }

        $question = trim((string)($_POST['poll_question'] ?? ''));
        $options = [];
        foreach ((array)($_POST['poll_options'] ?? []) as $option) {
            $option = trim((string)$option);
            if ($option !== '') {
                $options[] = Helpers::mbLimit($option, 80);
            }
        }
        if ($question !== '' || $options !== []) {
            if ($question === '' || count($options) < 2 || count($options) > 4) {
                throw new \InvalidArgumentException('Polls require a question and 2-4 options.');
            }
            $pollStart = !empty($metadata['scheduled_at']) ? strtotime((string)$metadata['scheduled_at']) : time();
            $metadata['poll'] = [
                'question' => Helpers::mbLimit($question, 120),
                'options' => $options,
                'closes_at' => date('Y-m-d H:i:s', ($pollStart ?: time()) + 86400),
            ];
        }

        return $metadata;
    }

    /**
     * Validate and store tweet attachment uploads.
     *
     * @return array<int, array{file_name:string,mime_type:string}>
     */
    private function handleTweetMediaUploads(): array
    {
        if (empty($_FILES['attachments']['name']) || !is_array($_FILES['attachments']['name'])) {
            return [];
        }

        $stored = [];
        $maxBytes = (int)Helpers::env('MAX_ATTACHMENT_SIZE_KB', '5120') * 1024;
        $allowedImages = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $allowedMedia = [
            'audio/mpeg' => 'mp3',
            'audio/mp3' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/aac' => 'aac',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/webm' => 'webm',
            'audio/flac' => 'flac',
            'audio/x-flac' => 'flac',
            'audio/x-m4a' => 'm4a',
            'audio/x-aiff' => 'aiff',
            'audio/aiff' => 'aiff',
            'audio/x-ms-wma' => 'wma',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/ogg' => 'ogv',
            'video/x-msvideo' => 'avi',
            'video/x-ms-wmv' => 'wmv',
            'video/x-matroska' => 'mkv',
        ];

        foreach ($_FILES['attachments']['name'] as $index => $_name) {
            $error = (int)($_FILES['attachments']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (count($stored) >= 4) {
                throw new \RuntimeException('Tweets can include at most 4 attachments.');
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Attachment upload failed.');
            }
            if ((int)$_FILES['attachments']['size'][$index] > $maxBytes) {
                throw new \RuntimeException('Attachment is too large.');
            }
            $tmp = (string)$_FILES['attachments']['tmp_name'][$index];
            $info = @getimagesize($tmp);
            $mime = is_array($info) && isset($info['mime']) ? (string)$info['mime'] : '';
            $extension = $mime !== '' && isset($allowedImages[$mime]) ? $allowedImages[$mime] : null;
            if ($extension === null) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime = (string)($finfo->file($tmp) ?: '');
                $extension = $allowedMedia[$mime] ?? null;
            }
            if ($extension === null) {
                throw new \RuntimeException('Attachments must be real image, audio, or video files.');
            }
            $filename = 'tweet_' . hash('sha256', microtime(true) . ':' . random_bytes(16) . ':' . $index) . '.' . $extension;
            $path = Database::instance()->dataDir() . '/uploads/' . $filename;
            if (!move_uploaded_file($tmp, $path)) {
                throw new \RuntimeException('Attachment could not be saved.');
            }
            $stored[] = ['file_name' => $filename, 'mime_type' => $mime];
        }

        return $stored;
    }

    /**
     * Delete stored media after a failed tweet create.
     *
     * @param array<int, array{file_name:string,mime_type:string}> $media
     */
    private function deleteStoredMedia(array $media): void
    {
        $dir = Database::instance()->dataDir() . '/uploads/';
        foreach ($media as $item) {
            $path = $dir . $item['file_name'];
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Return a form/action error.
     */
    private function fail(string $message, string $fallback): never
    {
        if (Helpers::wantsJson()) {
            Helpers::json(['ok' => false, 'error' => $message], 400);
        }
        Session::flash('error', $message);
        Helpers::redirect($fallback);
    }

    /**
     * Require a recent 2FA check before using admin-only tweet powers.
     */
    private function requireFreshAdminAction(): void
    {
        $admin = Auth::requireAdmin();
        if (!TwoFactor::isEnabled($admin)) {
            if (Helpers::wantsJson()) {
                Helpers::json(['ok' => false, 'error' => 'Admins must set up 2FA before using admin tools.', 'redirect' => '/settings'], 403);
            }
            Session::flash('error', 'Admins must set up 2FA before using admin tools.');
            Helpers::redirect('/settings');
        }
        $verifiedAt = (int)($_SESSION['admin_2fa_verified_at'] ?? 0);
        if ($verifiedAt <= 0 || $verifiedAt < time() - 600) {
            if (Helpers::wantsJson()) {
                Helpers::json(['ok' => false, 'error' => 'Confirm 2FA before taking an admin action.', 'redirect' => '/admin/verify'], 403);
            }
            Session::flash('error', 'Confirm 2FA before taking an admin action.');
            Helpers::redirect('/admin/verify');
        }
    }
}
