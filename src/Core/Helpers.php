<?php
declare(strict_types=1);

namespace Twitkey\Core;

final class Helpers
{
    /**
     * Load .env key/value pairs if a .env file exists.
     */
    public static function loadEnv(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key !== '' && getenv($key) === false) {
                $_ENV[$key] = $value;
                putenv($key . '=' . $value);
            }
        }
    }

    /**
     * Return an environment value with a fallback.
     */
    public static function env(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        return $value === false || $value === null ? $default : (string)$value;
    }

    /**
     * Escape text for safe HTML output.
     */
    public static function h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_COMPAT | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Return a UTF-8 character count for user-facing text.
     */
    public static function mbLength(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    /**
     * Return a UTF-8-safe substring for user-facing text.
     */
    public static function mbLimit(string $text, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
    }

    /**
     * Truncate UTF-8 text with a trailing ellipsis.
     */
    public static function mbEllipsis(string $text, int $length): string
    {
        if (self::mbLength($text) <= $length) {
            return $text;
        }
        return rtrim(self::mbLimit($text, max(0, $length - 3))) . '...';
    }

    /**
     * Return selectable site themes.
     *
     * @return array<string, string>
     */
    public static function themes(): array
    {
        return [
            'classic' => 'Classic 2009',
            'night' => 'Night',
            'forest' => 'Forest',
            'ruby' => 'Ruby',
            'ocean' => 'Ocean',
            'lavender' => 'Lavender',
            'gold' => 'Gold',
            'bubblegum' => 'Bubblegum',
            'grayscale' => 'Grayscale',
            'midnight' => 'Midnight',
            'terminal' => 'Terminal',
            'high_contrast' => 'High contrast',
            'custom' => 'Custom CSS',
        ];
    }

    /**
     * Return a starter stylesheet users can edit for their custom theme.
     */
    public static function customThemeTemplate(): string
    {
        return <<<'CSS'
body.theme-custom {
    --bg-body: #e8f5fd;
    --bg-white: #ffffff;
    --bg-sidebar: #ffffff;
    --bg-header: #1b7dba;
    --bg-header-top: #04538a;
    --text-primary: #333333;
    --text-secondary: #999999;
    --text-link: #0084b4;
    --text-link-hover: #003366;
    --border-main: #d0dfe8;
    --border-dark: #b0cce1;
    --border-input: #c0ccd4;
    --btn-primary-bg: #1b7dba;
    --btn-primary-bg-hover: #1269a0;
}

body.theme-custom .topbar {
    background: var(--bg-header-top);
}

body.theme-custom .subheader {
    background: var(--bg-header);
}

body.theme-custom .tweet-row:hover {
    background: #f8fcff;
}
CSS;
    }

    /**
     * Validate and normalize per-user custom theme CSS.
     */
    public static function sanitizeCustomThemeCss(string $css): string
    {
        $css = trim(str_replace(["\r\n", "\r"], "\n", $css));
        if ($css === '') {
            return '';
        }
        if (self::mbLength($css) > 20000) {
            throw new \InvalidArgumentException('Custom theme CSS must be 20,000 characters or less.');
        }
        $lower = strtolower($css);
        foreach (['<', '@import', 'javascript:', 'vbscript:', 'data:', 'expression(', 'behavior:', '-moz-binding', 'url('] as $blocked) {
            if (str_contains($lower, $blocked)) {
                throw new \InvalidArgumentException('Custom theme CSS contains a blocked token: ' . $blocked);
            }
        }
        return $css;
    }

    /**
     * Return safe custom CSS for rendering, or an empty string if invalid.
     */
    public static function customThemeCssForOutput(string $css): string
    {
        try {
            return self::sanitizeCustomThemeCss($css);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Render a view, optionally inside the base layout.
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $view, array $data = [], bool $layout = true): void
    {
        $viewPath = TWITKEY_ROOT . '/views/' . $view . '.php';
        if (!is_file($viewPath)) {
            throw new \RuntimeException('Missing view: ' . $view);
        }

        $currentUser = Auth::user();
        extract($data, EXTR_SKIP);
        ob_start();
        include $viewPath;
        $content = (string)ob_get_clean();

        if ($layout) {
            $title = $title ?? self::env('APP_NAME', 'Twitkey');
            $hideSidebar = $hideSidebar ?? false;
            $sidebar = $sidebar ?? self::sidebarData($currentUser);
            include TWITKEY_ROOT . '/views/layouts/base.php';
            return;
        }
        echo $content;
    }

    /**
     * Render a view to a string without the layout.
     *
     * @param array<string, mixed> $data
     */
    public static function renderPartial(string $view, array $data = []): string
    {
        $viewPath = TWITKEY_ROOT . '/views/' . $view . '.php';
        if (!is_file($viewPath)) {
            throw new \RuntimeException('Missing view: ' . $view);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $viewPath;
        return (string)ob_get_clean();
    }

    /**
     * Redirect and stop execution.
     */
    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 302);
        exit;
    }

    /**
     * Send JSON and stop execution.
     *
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_THROW_ON_ERROR);
        exit;
    }

    /**
     * True when the client prefers a JSON response.
     */
    public static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json')
            || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
            || isset($_GET['ajax']);
    }

    /**
     * Return the current CSRF token.
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['_csrf'];
    }

    /**
     * Return a hidden CSRF input.
     */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::h(self::csrfToken()) . '">';
    }

    /**
     * Verify the CSRF token for a state-changing request.
     */
    public static function verifyCsrf(): void
    {
        $token = (string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($token === '' || !hash_equals(self::csrfToken(), $token)) {
            if (self::wantsJson()) {
                self::json(['ok' => false, 'error' => 'Invalid CSRF token.'], 419);
            }
            http_response_code(419);
            Session::flash('error', 'Your form expired. Please try again.');
            self::redirect($_SERVER['HTTP_REFERER'] ?? '/');
        }
    }

    /**
     * Return a sanitized page number.
     */
    public static function page(): int
    {
        return max(1, (int)($_GET['page'] ?? 1));
    }

    /**
     * Return the SQL offset for a page.
     */
    public static function offset(int $page, int $perPage = 20): int
    {
        return max(0, ($page - 1) * $perPage);
    }

    /**
     * Return the public avatar URL for a user row.
     *
     * @param array<string, mixed>|null $user
     */
    public static function avatarUrl(?array $user): string
    {
        $avatar = $user['avatar'] ?? null;
        return $avatar ? '/media/' . rawurlencode((string)$avatar) : '/img/default_avatar.png';
    }

    /**
     * Return the public profile banner URL for a user row.
     *
     * @param array<string, mixed>|null $user
     */
    public static function bannerUrl(?array $user): ?string
    {
        $banner = $user['background'] ?? null;
        return $banner ? '/media/' . rawurlencode((string)$banner) : null;
    }

    /**
     * Link mentions, hashtags, and URLs after escaping tweet text.
     */
    public static function renderTweetBody(string $body): string
    {
        $escaped = self::h($body);
        $parts = preg_split('~(https?://[^\s<]+)~i', $escaped, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return nl2br($escaped, false);
        }

        $html = '';
        foreach ($parts as $part) {
            if (preg_match('~^https?://[^\s<]+$~i', $part) === 1) {
                $html .= '<a href="' . $part . '" rel="nofollow noopener" target="_blank">' . $part . '</a>';
                continue;
            }
            $part = preg_replace_callback('/(?<![\w@])@([A-Za-z0-9_]{1,15})/', static function (array $m): string {
                $u = $m[1];
                return '<a href="/' . rawurlencode($u) . '">@' . self::h($u) . '</a>';
            }, $part) ?? $part;
            $part = preg_replace_callback('/(?<![\w#])#([A-Za-z0-9_]{1,60})/', static function (array $m): string {
                $tag = $m[1];
                return '<a href="/search?q=%23' . rawurlencode($tag) . '">#' . self::h($tag) . '</a>';
            }, $part) ?? $part;
            $html .= $part;
        }
        return nl2br($html, false);
    }

    /**
     * Render safe rich embeds for known URL providers, with generic cards for others.
     */
    public static function renderEmbeds(string $body): string
    {
        if (preg_match_all('~https?://[^\s<]+~i', $body, $matches) !== 1) {
            return '';
        }

        $html = '';
        foreach (array_slice(array_unique($matches[0]), 0, 3) as $url) {
            $clean = rtrim($url, '.,;:!?)');
            if (!filter_var($clean, FILTER_VALIDATE_URL)) {
                continue;
            }
            $host = strtolower((string)(parse_url($clean, PHP_URL_HOST) ?: ''));
            $path = (string)(parse_url($clean, PHP_URL_PATH) ?: '');
            $embed = null;

            if (preg_match('/(^|\\.)(youtube\\.com|youtu\\.be)$/', $host) === 1) {
                $videoId = '';
                if ($host === 'youtu.be') {
                    $videoId = trim($path, '/');
                } else {
                    parse_str((string)(parse_url($clean, PHP_URL_QUERY) ?: ''), $query);
                    $videoId = (string)($query['v'] ?? '');
                    if ($videoId === '' && preg_match('~/shorts/([A-Za-z0-9_-]{6,})~', $path, $m) === 1) {
                        $videoId = $m[1];
                    }
                }
                if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId) === 1) {
                    $embed = '<iframe src="https://www.youtube-nocookie.com/embed/' . self::h($videoId) . '" title="YouTube video" loading="lazy" allowfullscreen></iframe>';
                }
            } elseif (preg_match('/(^|\\.)vimeo\\.com$/', $host) === 1 && preg_match('~/([0-9]{6,})~', $path, $m) === 1) {
                $embed = '<iframe src="https://player.vimeo.com/video/' . self::h($m[1]) . '" title="Vimeo video" loading="lazy" allowfullscreen></iframe>';
            } elseif ($host === 'open.spotify.com') {
                $spotifyPath = trim($path, '/');
                if (preg_match('~^(track|album|playlist|episode|show)/[A-Za-z0-9]+~', $spotifyPath) === 1) {
                    $embed = '<iframe src="https://open.spotify.com/embed/' . self::h($spotifyPath) . '" title="Spotify embed" loading="lazy"></iframe>';
                }
            } elseif ($host === 'soundcloud.com' || str_ends_with($host, '.soundcloud.com')) {
                $embed = '<iframe src="https://w.soundcloud.com/player/?url=' . rawurlencode($clean) . '" title="SoundCloud embed" loading="lazy"></iframe>';
            } elseif ($host === 'www.tiktok.com' && preg_match('~/video/([0-9]+)~', $path, $m) === 1) {
                $embed = '<iframe src="https://www.tiktok.com/embed/v2/' . self::h($m[1]) . '" title="TikTok video" loading="lazy"></iframe>';
            } elseif (($host === 'twitter.com' || $host === 'x.com') && preg_match('~/status/([0-9]+)~', $path, $m) === 1) {
                $embed = '<iframe src="https://platform.twitter.com/embed/Tweet.html?id=' . self::h($m[1]) . '" title="Post embed" loading="lazy"></iframe>';
            } elseif ($host === 'www.instagram.com' && preg_match('~/(p|reel)/([A-Za-z0-9_-]+)~', $path, $m) === 1) {
                $embed = '<iframe src="https://www.instagram.com/' . self::h($m[1]) . '/' . self::h($m[2]) . '/embed" title="Instagram embed" loading="lazy"></iframe>';
            }

            if ($embed !== null) {
                $html .= '<div class="tweet-embed">' . $embed . '</div>';
                continue;
            }

            $label = self::truncate($clean, 80);
            $html .= '<a class="tweet-link-card" href="' . self::h($clean) . '" target="_blank" rel="nofollow noopener">'
                . '<strong>' . self::h($host ?: 'Link') . '</strong>'
                . '<span>' . self::h($label) . '</span>'
                . '</a>';
        }
        return $html;
    }

    /**
     * Render verification and affiliation badges for a user row.
     *
     * @param array<string, mixed> $user
     */
    public static function renderBadges(array $user): string
    {
        $html = '';
        $affiliation = self::acceptedAffiliation((int)($user['id'] ?? 0));
        if (($user['verified_type'] ?? null) === 'business') {
            $html .= '<span class="tooltip-wrap" data-tooltip="Verified Business"><img src="/img/verified_badge.webp" class="image-badge verified-image-badge" alt="Verified Business"></span>';
        } elseif (($user['verified_type'] ?? null) === 'government') {
            $html .= '<span class="tooltip-wrap" data-tooltip="Verified Government"><img src="/img/verified_badge.webp" class="image-badge verified-image-badge" alt="Verified Government"></span>';
        } elseif ((int)($user['is_verified'] ?? 0) === 1 || $affiliation !== null) {
            $html .= '<span class="tooltip-wrap" data-tooltip="Verified"><img src="/img/verified_badge.webp" class="image-badge verified-image-badge" alt="Verified"></span>';
        }

        if ($affiliation) {
            $avatarSrc = self::avatarUrl($affiliation);
            $username = self::h($affiliation['username']);
            $html .= '<a href="/' . $username . '" data-tooltip="Affiliated with @' . $username . '" class="affiliation-link tooltip-wrap">';
            $html .= '<img src="' . $avatarSrc . '" class="affiliation-avatar" alt="@' . $username . '">';
            $html .= '</a>';
        }
        return $html;
    }

    /**
     * Render the administrator badge as an avatar-corner overlay.
     *
     * @param array<string, mixed> $user
     */
    public static function adminAvatarBadge(array $user): string
    {
        if ((int)($user['is_admin'] ?? 0) !== 1) {
            return '';
        }
        return '<span class="avatar-admin-badge tooltip-wrap" data-tooltip="Administrator"><img src="/img/admin_badge.png" alt="Administrator"></span>';
    }

    /**
     * Render a linked display name with badges.
     *
     * @param array<string, mixed> $user
     */
    public static function renderUserName(array $user): string
    {
        $username = self::h($user['username'] ?? '');
        $display = self::h($user['display_name'] ?? $user['username'] ?? '');
        $lock = (int)($user['is_private'] ?? 0) === 1 ? '<span class="lock-badge tooltip-wrap" data-tooltip="Private account">🔒</span>' : '';
        return '<a href="/' . $username . '" class="username">' . $display . '</a>' . self::renderBadges($user) . $lock;
    }

    /**
     * Render a small "Follows you" indicator when the shown account follows the viewer.
     *
     * @param array<string, mixed> $user
     */
    public static function followsYouBadge(array $user): string
    {
        $viewer = Auth::user();
        $userId = (int)($user['id'] ?? $user['user_id'] ?? 0);
        if (!$viewer || $userId <= 0 || $userId === (int)$viewer['id']) {
            return '';
        }
        $followsViewer = Database::instance()->one(
            'SELECT id FROM follows WHERE follower_id = :user_id AND following_id = :viewer_id LIMIT 1',
            ['user_id' => $userId, 'viewer_id' => (int)$viewer['id']]
        ) !== null;
        return $followsViewer ? '<span class="follows-you tooltip-wrap" data-tooltip="This user follows you">Follows you</span>' : '';
    }

    /**
     * Return the accepted business affiliation for a user, cached per request.
     *
     * @return array<string, mixed>|null
     */
    public static function acceptedAffiliation(int $userId): ?array
    {
        static $cache = [];
        if ($userId <= 0) {
            return null;
        }
        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }
        $db = Database::instance();
        $cache[$userId] = $db->one(
            'SELECT b.* FROM affiliations a JOIN users b ON b.id = a.business_id WHERE a.affiliated_id = :id AND a.status = :status LIMIT 1',
            ['id' => $userId, 'status' => 'accepted']
        );
        return $cache[$userId];
    }

    /**
     * Render a compact relative timestamp.
     */
    public static function timeAgo(string $datetime): string
    {
        $time = strtotime($datetime);
        if ($time === false) {
            return self::h($datetime);
        }
        $diff = max(0, time() - $time);
        if ($diff < 60) {
            return 'less than a minute ago';
        }
        if ($diff < 3600) {
            $m = (int)floor($diff / 60);
            return 'about ' . $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 86400) {
            $h = (int)floor($diff / 3600);
            return 'about ' . $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 604800) {
            $d = (int)floor($diff / 86400);
            return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
        }
        return date('M j, Y', $time);
    }

    /**
     * Truncate text safely for compact widgets.
     */
    public static function truncate(string $text, int $length): string
    {
        return self::mbEllipsis($text, $length);
    }

    /**
     * Return data used by the right sidebar.
     *
     * @param array<string, mixed>|null $currentUser
     * @return array<string, mixed>
     */
    public static function sidebarData(?array $currentUser): array
    {
        return [
            'trends' => self::trendingTopics(),
            'suggestions' => \Twitkey\Models\User::suggestions($currentUser ? (int)$currentUser['id'] : null, 3),
        ];
    }

    /**
     * Return a persisted app setting.
     */
    public static function appSetting(string $key, string $default = ''): string
    {
        $row = Database::instance()->one('SELECT setting_value FROM app_settings WHERE setting_key = :key', ['key' => $key]);
        return $row ? (string)$row['setting_value'] : $default;
    }

    /**
     * Persist an app setting.
     */
    public static function setAppSetting(string $key, string $value, ?int $updatedBy = null): void
    {
        $db = Database::instance();
        if ($db->isMysql()) {
            $db->execute(
                'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
                 VALUES (:key, :value, :updated_by, :updated_at)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
                ['key' => $key, 'value' => $value, 'updated_by' => $updatedBy, 'updated_at' => date('Y-m-d H:i:s')]
            );
            return;
        }
        $db->execute(
            'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
             VALUES (:key, :value, :updated_by, :updated_at)
             ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_by = excluded.updated_by, updated_at = excluded.updated_at',
            ['key' => $key, 'value' => $value, 'updated_by' => $updatedBy, 'updated_at' => date('Y-m-d H:i:s')]
        );
    }

    /**
     * True when site maintenance mode is enabled.
     */
    public static function maintenanceModeEnabled(): bool
    {
        return self::appSetting('maintenance_mode', '0') === '1';
    }

    /**
     * Return cached trending hashtags.
     *
     * @return array<int, array{tag:string,count:int}>
     */
    public static function trendingTopics(): array
    {
        $db = Database::instance();
        $cache = $db->dataDir() . '/cache/trends.json';
        if (is_file($cache) && (time() - (int)filemtime($cache) < 300)) {
            $decoded = json_decode((string)file_get_contents($cache), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $cutoffSql = $db->isMysql() ? 'DATE_SUB(NOW(), INTERVAL 1 DAY)' : "datetime('now', '-1 day')";
        $publishedSql = $db->isMysql() ? '(t.scheduled_at IS NULL OR t.scheduled_at <= NOW())' : "(t.scheduled_at IS NULL OR t.scheduled_at <= datetime('now'))";
        $rows = $db->all(
            "SELECT h.tag, COUNT(*) AS count
             FROM hashtags h
             JOIN tweet_hashtags th ON th.hashtag_id = h.id
             JOIN tweets t ON t.id = th.tweet_id
             JOIN users u ON u.id = t.user_id
             WHERE t.created_at >= {$cutoffSql} AND {$publishedSql} AND t.is_deleted = 0 AND u.is_suspended = 0 AND u.is_deleted = 0 AND u.is_private = 0 AND u.post_visibility = 'public'
             GROUP BY h.id, h.tag
             ORDER BY count DESC, h.tag ASC
             LIMIT 10"
        );
        $trends = array_map(static fn(array $row): array => ['tag' => (string)$row['tag'], 'count' => (int)$row['count']], $rows);
        file_put_contents($cache, json_encode($trends, JSON_THROW_ON_ERROR));
        return $trends;
    }

    /**
     * True when a user meets community note eligibility.
     *
     * @param array<string, mixed>|null $user
     */
    public static function eligibleForNotes(?array $user): bool
    {
        if (!$user || (int)$user['tweet_count'] < 5) {
            return false;
        }
        $created = strtotime((string)$user['created_at']);
        return $created !== false && $created <= strtotime('-7 days');
    }
}
