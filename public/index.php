<?php
declare(strict_types=1);

define('TWITKEY_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Twitkey\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = TWITKEY_ROOT . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Twitkey\Controllers\AdminController;
use Twitkey\Controllers\ApiController;
use Twitkey\Controllers\AuthController;
use Twitkey\Controllers\HomeController;
use Twitkey\Controllers\NotificationsController;
use Twitkey\Controllers\TweetController;
use Twitkey\Controllers\UserController;
use Twitkey\Core\Auth;
use Twitkey\Core\Database;
use Twitkey\Core\Helpers;
use Twitkey\Core\Router;
use Twitkey\Core\Session;
use Twitkey\Models\User;

Helpers::loadEnv(TWITKEY_ROOT . '/.env');
if (Helpers::env('APP_DEBUG', 'false') === 'true') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; media-src 'self' blob:; connect-src 'self'; frame-src https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com https://open.spotify.com https://w.soundcloud.com https://player.twitch.tv https://www.tiktok.com https://www.instagram.com https://platform.twitter.com https://x.com https://twitter.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

Session::start();
Database::instance();

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$currentUser = Auth::user();
$maintenanceAllowed = ($requestPath === '/login' && in_array($requestMethod, ['GET', 'POST'], true))
    || str_starts_with($requestPath, '/login/2fa')
    || ($requestPath === '/logout' && $requestMethod === 'GET');
if (Helpers::maintenanceModeEnabled() && !User::isOwnerRow($currentUser) && !$maintenanceAllowed) {
    http_response_code(503);
    header('Retry-After: 3600');
    Helpers::render('auth/maintenance', ['user' => $currentUser, 'title' => 'Maintenance Mode'], false);
    exit;
}
if ($currentUser && ((int)($currentUser['is_suspended'] ?? 0) === 1 || (int)($currentUser['is_deleted'] ?? 0) === 1) && $requestPath !== '/logout') {
    http_response_code(403);
    Helpers::render('auth/suspended', ['user' => $currentUser, 'title' => 'Account Suspended'], false);
    exit;
}

$router = new Router();
$router->add('GET', '/', [HomeController::class, 'timeline']);
$router->add('POST', '/', [TweetController::class, 'create']);
$router->add('GET', '/public', [HomeController::class, 'publicTimeline']);
$router->add('GET', '/help', [HomeController::class, 'help']);
$router->add('GET', '/privacy', [HomeController::class, 'privacy']);
$router->add('GET', '/policy', [HomeController::class, 'privacy']);
$router->add('GET', '/terms', [HomeController::class, 'terms']);
$router->add('GET', '/login', [AuthController::class, 'loginForm']);
$router->add('POST', '/login', [AuthController::class, 'login']);
$router->add('GET', '/login/2fa', [AuthController::class, 'twoFactorForm']);
$router->add('POST', '/login/2fa/totp', [AuthController::class, 'verifyTwoFactorTotp']);
$router->add('GET', '/login/2fa/passkey/options', [AuthController::class, 'passkeyLoginOptions']);
$router->add('POST', '/login/2fa/passkey/verify', [AuthController::class, 'verifyPasskeyLogin']);
$router->add('GET', '/register', [AuthController::class, 'registerForm']);
$router->add('POST', '/register', [AuthController::class, 'register']);
$router->add('GET', '/logout', [AuthController::class, 'logout']);
$router->add('POST', '/accounts/add', [AuthController::class, 'addAccount']);
$router->add('POST', '/accounts/switch/{id}', [AuthController::class, 'switchAccount']);
$router->add('POST', '/accounts/remove/{id}', [AuthController::class, 'removeAccount']);
$router->add('GET', '/settings', [UserController::class, 'settings']);
$router->add('POST', '/settings', [UserController::class, 'updateSettings']);
$router->add('POST', '/settings/2fa/totp/create', [UserController::class, 'createTotpSecret']);
$router->add('POST', '/settings/2fa/totp/enable', [UserController::class, 'enableTotp']);
$router->add('POST', '/settings/2fa/totp/disable', [UserController::class, 'disableTotp']);
$router->add('GET', '/settings/2fa/passkeys/options', [UserController::class, 'passkeyOptions']);
$router->add('POST', '/settings/2fa/passkeys/register', [UserController::class, 'registerPasskey']);
$router->add('POST', '/settings/2fa/passkeys/{id}/delete', [UserController::class, 'deletePasskey']);
$router->add('GET', '/settings/affiliations', [UserController::class, 'affiliations']);
$router->add('POST', '/settings/affiliations', [UserController::class, 'updateAffiliations']);
$router->add('POST', '/tweet', [TweetController::class, 'create']);
$router->add('GET', '/tweet/{id}', [TweetController::class, 'show']);
$router->add('POST', '/tweet/{id}/reply', [TweetController::class, 'reply']);
$router->add('POST', '/tweet/{id}/retweet', [TweetController::class, 'retweet']);
$router->add('POST', '/tweet/{id}/favorite', [TweetController::class, 'favorite']);
$router->add('POST', '/tweet/{id}/poll/{option_id}', [TweetController::class, 'votePoll']);
$router->add('DELETE', '/tweet/{id}', [TweetController::class, 'delete']);
$router->add('POST', '/follow/{username}', [UserController::class, 'follow']);
$router->add('POST', '/follow_requests/{id}/action', [UserController::class, 'followRequestAction']);
$router->add('GET', '/replies', [NotificationsController::class, 'replies']);
$router->add('GET', '/notifications', [NotificationsController::class, 'index']);
$router->add('GET', '/direct_messages', [HomeController::class, 'dms']);
$router->add('POST', '/direct_messages/{user}', [HomeController::class, 'sendDm']);
$router->add('GET', '/search', [HomeController::class, 'search']);
$router->add('GET', '/notes/pending', [TweetController::class, 'pendingNotes']);
$router->add('POST', '/tweet/{id}/note', [TweetController::class, 'addNote']);
$router->add('POST', '/note/{id}/vote', [TweetController::class, 'voteNote']);
$router->add('GET', '/admin', [AdminController::class, 'dashboard']);
$router->add('POST', '/admin/site-alert', [AdminController::class, 'siteAlert']);
$router->add('POST', '/admin/maintenance', [AdminController::class, 'maintenance']);
$router->add('GET', '/admin/users', [AdminController::class, 'users']);
$router->add('POST', '/admin/users/{id}/action', [AdminController::class, 'userAction']);
$router->add('GET', '/admin/tweets', [AdminController::class, 'tweets']);
$router->add('POST', '/admin/tweets/{id}/action', [AdminController::class, 'tweetAction']);
$router->add('GET', '/admin/notes', [AdminController::class, 'notes']);
$router->add('POST', '/admin/notes/{id}/action', [AdminController::class, 'noteAction']);
$router->add('GET', '/admin/setup', [AdminController::class, 'setup']);
$router->add('GET', '/api/username', [ApiController::class, 'username']);
$router->add('GET', '/api/suggest', [ApiController::class, 'suggest']);
$router->add('GET', '/api/gifs', [ApiController::class, 'gifs']);
$router->add('GET', '/api/locations', [ApiController::class, 'locations']);
$router->add('GET', '/api/site_alert', [ApiController::class, 'siteAlert']);
$router->add('GET', '/api/realtime', [ApiController::class, 'realtime']);
$router->add('GET', '/api/timeline', [ApiController::class, 'timeline']);
$router->add('GET', '/api/polls', [ApiController::class, 'polls']);
$router->add('GET', '/api/messages', [ApiController::class, 'messages']);
$router->add('GET', '/gif_proxy', [ApiController::class, 'gifProxy']);
$router->add('GET', '/media/{file}', [ApiController::class, 'media']);
$router->add('GET', '/{username}/followers', [UserController::class, 'followers']);
$router->add('GET', '/{username}/following', [UserController::class, 'following']);
$router->add('GET', '/{username}', [UserController::class, 'profile']);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
