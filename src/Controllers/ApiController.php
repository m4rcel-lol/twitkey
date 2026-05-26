<?php
declare(strict_types=1);

namespace Twitkey\Controllers;

use Twitkey\Core\Auth;
use Twitkey\Core\Database;
use Twitkey\Core\Helpers;
use Twitkey\Models\Notification;
use Twitkey\Models\Tweet;
use Twitkey\Models\User;

final class ApiController
{
    /**
     * Return username availability.
     */
    public function username(): void
    {
        $username = (string)($_GET['username'] ?? '');
        Helpers::json(['ok' => true, 'available' => User::usernameAvailable($username)]);
    }

    /**
     * Return simple mention/hashtag suggestions for compose autocomplete.
     */
    public function suggest(): void
    {
        $type = (string)($_GET['type'] ?? '@');
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') {
            Helpers::json(['ok' => true, 'items' => []]);
        }
        if ($type === '#') {
            $rows = \Twitkey\Core\Database::instance()->all(
                'SELECT tag AS value FROM hashtags WHERE lower(tag) LIKE lower(:term) ORDER BY tag ASC LIMIT 8',
                ['term' => $q . '%']
            );
        } else {
            $rows = User::search($q, 8);
            $rows = array_map(static fn(array $u): array => ['value' => (string)$u['username']], $rows);
        }
        Helpers::json(['ok' => true, 'items' => $rows]);
    }

    /**
     * Search GIFs through Klipy or a compatible configurable endpoint.
     */
    public function gifs(): void
    {
        $query = trim((string)($_GET['q'] ?? ''));
        if ($query === '') {
            Helpers::json(['ok' => true, 'items' => []]);
        }

        $endpoint = Helpers::env('GIF_API_SEARCH_URL', 'https://api.klipy.com/v2/search?q={query}&key={key}&limit=12&media_filter=gif,tinygif,mediumgif,nanogif,preview&contentfilter=low');
        $endpoint = str_replace(
            ['{query}', '{key}'],
            [rawurlencode($query), rawurlencode(Helpers::env('KLIPY_API_KEY', ''))],
            $endpoint
        );
        $json = $this->fetchJson($endpoint);
        $items = $this->extractGifItems($json);
        if ($items === [] && Helpers::env('KLIPY_API_KEY', '') !== '') {
            $fallback = 'https://api.klipy.com/api/v1/{key}/gifs/search?q={query}&per_page=12&rating=g';
            $json = $this->fetchJson(str_replace(
                ['{query}', '{key}'],
                [rawurlencode($query), rawurlencode(Helpers::env('KLIPY_API_KEY', ''))],
                $fallback
            ));
            $items = $this->extractGifItems($json);
        }
        $results = [];

        foreach ($items as $item) {
            $url = $item['url'] ?? $item['gif'] ?? $item['media'] ?? null;
            if (is_array($url)) {
                $url = $url['url'] ?? null;
            }
            if (!is_string($url) || !str_starts_with($url, 'https://')) {
                continue;
            }
            $results[] = [
                'url' => $url,
                'title' => Helpers::mbLimit((string)($item['title'] ?? $item['name'] ?? 'GIF'), 80),
            ];
            if (count($results) >= 12) {
                break;
            }
        }

        Helpers::json(['ok' => true, 'items' => $results]);
    }

    /**
     * Search map locations through a configurable geocoder endpoint.
     */
    public function locations(): void
    {
        $query = trim((string)($_GET['q'] ?? ''));
        if ($query === '') {
            Helpers::json(['ok' => true, 'items' => []]);
        }

        $endpoint = Helpers::env('LOCATION_SEARCH_URL', 'https://nominatim.openstreetmap.org/search?format=json&limit=6&q={query}');
        $json = $this->fetchJson(str_replace('{query}', rawurlencode($query), $endpoint));
        $items = [];
        foreach ((array)$json as $row) {
            if (!isset($row['lat'], $row['lon'])) {
                continue;
            }
            $items[] = [
                'label' => Helpers::mbLimit((string)($row['display_name'] ?? $row['name'] ?? 'Selected location'), 160),
                'lat' => (float)$row['lat'],
                'lng' => (float)$row['lon'],
            ];
        }

        Helpers::json(['ok' => true, 'items' => $items]);
    }

    /**
     * Serve uploaded avatars and banners through a validated local media endpoint.
     */
    public function media(string $file): void
    {
        $isCurrentUpload = preg_match('/^(avatar|banner)_[a-f0-9]{64}\.(jpg|gif)$/', $file) === 1;
        $isLegacyAvatar = preg_match('/^[a-f0-9]{64}\.jpg$/', $file) === 1;
        $isTweetMedia = preg_match('/^tweet_[a-f0-9]{64}\.(jpg|png|gif|webp|mp3|m4a|aac|wav|ogg|flac|aiff|wma|mp4|mov|webm|ogv|avi|wmv|mkv)$/', $file) === 1;
        if (!$isCurrentUpload && !$isLegacyAvatar && !$isTweetMedia) {
            http_response_code(404);
            return;
        }

        $dataDir = Database::instance()->dataDir();
        $path = ($isCurrentUpload || $isTweetMedia) ? $dataDir . '/uploads/' . $file : $dataDir . '/avatars/' . $file;
        if (!is_file($path)) {
            http_response_code(404);
            return;
        }

        $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'aiff' => 'audio/aiff',
            'wma' => 'audio/x-ms-wma',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'ogv' => 'video/ogg',
            'avi' => 'video/x-msvideo',
            'wmv' => 'video/x-ms-wmv',
            'mkv' => 'video/x-matroska',
            default => 'image/jpeg',
        };

        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }

    /**
     * Return the active site alert for client polling.
     */
    public function siteAlert(): void
    {
        $alert = Database::instance()->one(
            'SELECT id, message, is_active, updated_at FROM site_alerts WHERE is_active = 1 AND message <> :empty ORDER BY updated_at DESC, id DESC LIMIT 1',
            ['empty' => '']
        );
        Helpers::json(['ok' => true, 'alert' => $alert]);
    }

    /**
     * Return current realtime counters for notification and message badges.
     */
    public function realtime(): void
    {
        $user = Auth::user();
        if (!$user) {
            Helpers::json(['ok' => true, 'notifications' => 0, 'messages' => 0]);
        }
        $messages = Database::instance()->one(
            'SELECT COUNT(*) AS count FROM direct_messages WHERE recipient_id = :id AND is_read = 0',
            ['id' => (int)$user['id']]
        );
        Helpers::json([
            'ok' => true,
            'notifications' => Notification::unreadCount((int)$user['id']),
            'messages' => (int)($messages['count'] ?? 0),
        ]);
    }

    /**
     * Return newer feed rows for near-realtime timeline polling.
     */
    public function timeline(): void
    {
        $scope = (string)($_GET['scope'] ?? 'public');
        $sinceId = max(0, (int)($_GET['since_id'] ?? 0));
        $currentUser = Auth::user();
        $tweets = [];

        if ($scope === 'home') {
            $user = Auth::requireLogin();
            $tweets = Tweet::newerForUser((int)$user['id'], $sinceId);
        } elseif ($scope === 'mentions') {
            $user = Auth::requireLogin();
            $tweets = Tweet::newerMentionsFor((string)$user['username'], $sinceId, (int)$user['id']);
        } elseif ($scope === 'profile') {
            $profile = User::findByUsername((string)($_GET['username'] ?? ''));
            if ($profile && User::canViewPosts($profile, $currentUser)) {
                $tab = (string)($_GET['tab'] ?? 'tweets');
                $tweets = Tweet::newerForProfile((int)$profile['id'], $tab, $sinceId, Auth::isAdmin());
            }
        } elseif ($scope === 'replies') {
            $parent = Tweet::findWithUser((int)($_GET['tweet_id'] ?? 0), true);
            if ($parent && Tweet::canBeViewedBy($parent, $currentUser)) {
                $tweets = array_values(array_filter(
                    Tweet::newerRepliesTo((int)$parent['id'], $sinceId),
                    static fn(array $reply): bool => Tweet::canBeViewedBy($reply, $currentUser)
                ));
            }
        } else {
            $tweets = Tweet::newerPublic($sinceId);
        }

        $html = '';
        foreach ($tweets as $tweet) {
            $html .= Helpers::renderPartial('partials/tweet_row', ['tweet' => $tweet, 'currentUser' => $currentUser]);
        }
        Helpers::json(['ok' => true, 'html' => $html, 'count' => count($tweets)]);
    }

    /**
     * Return refreshed poll tweet rows for visible poll cards.
     */
    public function polls(): void
    {
        $ids = array_map('intval', explode(',', (string)($_GET['tweet_ids'] ?? '')));
        $rows = Tweet::visibleRowsByIds($ids, Auth::user());
        $html = [];
        foreach ($rows as $row) {
            $html[(string)(int)$row['id']] = Helpers::renderPartial('partials/tweet_row', ['tweet' => $row, 'currentUser' => Auth::user()]);
        }
        Helpers::json(['ok' => true, 'rows' => $html]);
    }

    /**
     * Return newer direct messages in the selected conversation.
     */
    public function messages(): void
    {
        $user = Auth::requireLogin();
        $selected = User::findByUsername((string)($_GET['user'] ?? ''));
        if (!$selected) {
            Helpers::json(['ok' => false, 'error' => 'Conversation not found.'], 404);
        }
        $sinceId = max(0, (int)($_GET['since_id'] ?? 0));
        $db = Database::instance();
        $messages = $db->all(
            'SELECT dm.*, s.username AS sender_username, s.display_name AS sender_display_name, s.avatar AS sender_avatar,
                    s.is_admin AS sender_is_admin, s.is_system AS sender_is_system, s.is_verified AS sender_is_verified, s.is_private AS sender_is_private, s.verified_type AS sender_verified_type
             FROM direct_messages dm
             JOIN users s ON s.id = dm.sender_id
             WHERE dm.id > :since_id
               AND ((dm.sender_id = :me AND dm.recipient_id = :them) OR (dm.sender_id = :them AND dm.recipient_id = :me))
             ORDER BY dm.created_at ASC',
            ['since_id' => $sinceId, 'me' => (int)$user['id'], 'them' => (int)$selected['id']]
        );
        $db->execute('UPDATE direct_messages SET is_read = 1 WHERE recipient_id = :me AND sender_id = :them', ['me' => (int)$user['id'], 'them' => (int)$selected['id']]);

        $html = '';
        foreach ($messages as $message) {
            $html .= Helpers::renderPartial('partials/dm_message', ['message' => $message, 'currentUser' => $user]);
        }
        $count = $db->one(
            'SELECT COUNT(*) AS count FROM direct_messages WHERE (sender_id = :me AND recipient_id = :them) OR (sender_id = :them AND recipient_id = :me)',
            ['me' => (int)$user['id'], 'them' => (int)$selected['id']]
        );
        Helpers::json(['ok' => true, 'html' => $html, 'count' => (int)($count['count'] ?? 0)]);
    }

    /**
     * Proxy remote GIFs so older stored URLs are not blocked by browser hotlink rules.
     */
    public function gifProxy(): void
    {
        $url = (string)($_GET['url'] ?? '');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
            http_response_code(404);
            return;
        }
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '' || preg_match('/(^|\\.)(klipy\\.com|tenor\\.com|giphy\\.com|wikimedia\\.org|wikipedia\\.org)$/', $host) !== 1) {
            http_response_code(404);
            return;
        }
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'header' => "User-Agent: Twitkey/1.0\r\nAccept: image/gif,image/webp,image/*,*/*\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false || $body === '') {
            http_response_code(404);
            return;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($body) ?: 'image/gif';
        if (!in_array($mime, ['image/gif', 'image/webp', 'image/png', 'image/jpeg'], true)) {
            http_response_code(404);
            return;
        }
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        echo $body;
    }

    /**
     * Fetch JSON from an external API with a short timeout.
     *
     * @return mixed
     */
    private function fetchJson(string $url): mixed
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 4,
                'header' => "User-Agent: Twitkey/1.0\r\nAccept: application/json\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return [];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Support several common GIF API response shapes without binding to one provider.
     *
     * @param mixed $json
     * @return array<int, array<string, mixed>>
     */
    private function extractGifItems(mixed $json): array
    {
        if (!is_array($json)) {
            return [];
        }
        if (isset($json['data']) && is_array($json['data'])) {
            if (isset($json['data']['data']) && is_array($json['data']['data'])) {
                $items = [];
                foreach ($json['data']['data'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $file = $row['file'] ?? [];
                    if (is_array($file)) {
                        foreach (['hd', 'md', 'sm', 'xs'] as $size) {
                            if (isset($file[$size]['gif']['url']) && is_string($file[$size]['gif']['url'])) {
                                $items[] = ['url' => $file[$size]['gif']['url'], 'title' => $row['title'] ?? 'GIF'];
                                continue 2;
                            }
                        }
                    }
                }
                return $items;
            }
            $items = [];
            foreach ($json['data'] as $row) {
                if (isset($row['images']['fixed_height']['url'])) {
                    $items[] = ['url' => $row['images']['fixed_height']['url'], 'title' => $row['title'] ?? 'GIF'];
                } elseif (isset($row['media_formats']['gif']['url'])) {
                    $items[] = ['url' => $row['media_formats']['gif']['url'], 'title' => $row['content_description'] ?? 'GIF'];
                } elseif (is_array($row)) {
                    $items[] = $row;
                }
            }
            return $items;
        }
        if (isset($json['results']) && is_array($json['results'])) {
            $items = [];
            foreach ($json['results'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $media = $row['media_formats'] ?? [];
                if (is_array($media)) {
                    foreach (['gif', 'mediumgif', 'tinygif', 'nanogif', 'preview'] as $format) {
                        if (isset($media[$format]['url']) && is_string($media[$format]['url'])) {
                            $items[] = [
                                'url' => $media[$format]['url'],
                                'title' => $row['content_description'] ?? $row['title'] ?? 'GIF',
                            ];
                            continue 2;
                        }
                    }
                }
                $items[] = $row;
            }
            return $items;
        }
        if (isset($json['query']['pages']) && is_array($json['query']['pages'])) {
            $items = [];
            foreach ($json['query']['pages'] as $page) {
                $info = $page['imageinfo'][0] ?? null;
                if (!is_array($info) || ($info['mime'] ?? '') !== 'image/gif' || empty($info['url'])) {
                    continue;
                }
                $items[] = [
                    'url' => (string)$info['url'],
                    'title' => preg_replace('/^File:/', '', (string)($page['title'] ?? 'GIF')) ?: 'GIF',
                ];
            }
            return $items;
        }
        return array_is_list($json) ? $json : [];
    }
}
