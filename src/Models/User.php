<?php
declare(strict_types=1);

namespace Twitkey\Models;

use Twitkey\Core\Database;
use Twitkey\Core\Helpers;

final class User
{
    /**
     * True when a row is the configured Twitkey owner account.
     *
     * @param array<string, mixed>|null $user
     */
    public static function isOwnerRow(?array $user): bool
    {
        if ($user === null) {
            return false;
        }
        $id = (int)($user['id'] ?? 0);
        $username = strtolower((string)($user['username'] ?? ''));
        return ($id === 1 && $username === 'm5rcel')
            || ($id === 2 && $username === 'twitkey');
    }

    /**
     * True when an id currently resolves to the configured owner account.
     */
    public static function isOwnerId(int $id): bool
    {
        return self::isOwnerRow(self::find($id));
    }

    /**
     * Create a user and return the new id.
     *
     * @param array{username:string,display_name:string,email:string,password:string} $data
     */
    public static function create(array $data): int
    {
        $db = Database::instance();
        $hash = password_hash($data['password'], PASSWORD_BCRYPT);
        $db->execute(
            'INSERT INTO users (username, display_name, email, password) VALUES (:username, :display_name, :email, :password)',
            [
                'username' => strtolower($data['username']),
                'display_name' => $data['display_name'],
                'email' => strtolower($data['email']),
                'password' => $hash,
            ]
        );
        return $db->lastInsertId();
    }

    /**
     * Find a user by id.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Database::instance()->one('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    /**
     * Find a user by username.
     *
     * @return array<string, mixed>|null
     */
    public static function findByUsername(string $username): ?array
    {
        $username = ltrim(strtolower($username), '@');
        return Database::instance()->one('SELECT * FROM users WHERE lower(username) = lower(:username)', ['username' => $username]);
    }

    /**
     * Find a user by username or email login.
     *
     * @return array<string, mixed>|null
     */
    public static function findByLogin(string $login): ?array
    {
        return Database::instance()->one(
            'SELECT * FROM users WHERE lower(username) = lower(:login) OR lower(email) = lower(:login) LIMIT 1',
            ['login' => trim($login)]
        );
    }

    /**
     * Return true when a username is syntactically valid and not taken.
     */
    public static function usernameAvailable(string $username): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,15}$/', $username)) {
            return false;
        }
        return self::findByUsername($username) === null;
    }

    /**
     * Return true when an email is valid and not used by another user.
     */
    public static function emailAvailable(string $email, int $exceptUserId = 0): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $row = Database::instance()->one(
            'SELECT id FROM users WHERE lower(email) = lower(:email) AND id <> :id LIMIT 1',
            ['email' => strtolower($email), 'id' => $exceptUserId]
        );
        return $row === null;
    }

    /**
     * Update editable profile fields for a user.
     *
     * @param array<string, string|null> $fields
     */
    public static function updateProfile(int $id, array $fields): void
    {
        Database::instance()->execute(
            'UPDATE users
             SET display_name = :display_name, email = COALESCE(:email, email), bio = :bio, location = :location, website = :website,
                 avatar = CASE WHEN :avatar IS NOT NULL THEN :avatar WHEN :remove_avatar = 1 THEN NULL ELSE avatar END,
                 background = CASE WHEN :background IS NOT NULL THEN :background WHEN :remove_background = 1 THEN NULL ELSE background END,
                 is_private = :is_private, follow_privacy = :follow_privacy, post_visibility = :post_visibility,
                 dm_privacy = :dm_privacy, theme_choice = :theme_choice, custom_theme_css = :custom_theme_css, updated_at = :updated_at
             WHERE id = :id',
            [
                'display_name' => $fields['display_name'] ?? '',
                'email' => array_key_exists('email', $fields) ? strtolower((string)$fields['email']) : null,
                'bio' => $fields['bio'] ?? '',
                'location' => $fields['location'] ?? '',
                'website' => $fields['website'] ?? '',
                'avatar' => $fields['avatar'] ?? null,
                'background' => $fields['background'] ?? null,
                'remove_avatar' => (int)($fields['remove_avatar'] ?? 0),
                'remove_background' => (int)($fields['remove_background'] ?? 0),
                'is_private' => (int)($fields['is_private'] ?? 0),
                'follow_privacy' => $fields['follow_privacy'] ?? 'everyone',
                'post_visibility' => $fields['post_visibility'] ?? 'public',
                'dm_privacy' => $fields['dm_privacy'] ?? 'mutuals',
                'theme_choice' => $fields['theme_choice'] ?? 'classic',
                'custom_theme_css' => $fields['custom_theme_css'] ?? '',
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $id,
            ]
        );
    }

    /**
     * True when a viewer can see a user's posts.
     *
     * @param array<string, mixed> $profile
     * @param array<string, mixed>|null $viewer
     */
    public static function canViewPosts(array $profile, ?array $viewer): bool
    {
        if (((int)($profile['is_suspended'] ?? 0) === 1 || (int)($profile['is_deleted'] ?? 0) === 1) && (int)($viewer['is_admin'] ?? 0) !== 1) {
            return false;
        }
        if ((int)($viewer['is_admin'] ?? 0) === 1 || ($viewer && (int)$viewer['id'] === (int)$profile['id'])) {
            return true;
        }
        $followersOnly = (int)($profile['is_private'] ?? 0) === 1 || ($profile['post_visibility'] ?? 'public') === 'followers';
        if (!$followersOnly) {
            return true;
        }
        return $viewer !== null && Follow::isFollowing((int)$viewer['id'], (int)$profile['id']);
    }

    /**
     * True when a sender can message a recipient.
     *
     * @param array<string, mixed> $sender
     * @param array<string, mixed> $recipient
     */
    public static function canMessage(array $sender, array $recipient): bool
    {
        if ((int)$sender['id'] === (int)$recipient['id']) {
            return false;
        }
        if ((int)($recipient['is_suspended'] ?? 0) === 1 || (int)($sender['is_suspended'] ?? 0) === 1 || (int)($recipient['is_deleted'] ?? 0) === 1 || (int)($sender['is_deleted'] ?? 0) === 1) {
            return false;
        }
        return match (($recipient['dm_privacy'] ?? 'mutuals')) {
            'everyone' => true,
            'none' => false,
            default => Follow::isMutual((int)$sender['id'], (int)$recipient['id']),
        };
    }

    /**
     * Search users by username, display name, or bio.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $query, int $limit = 10): array
    {
        $term = '%' . $query . '%';
        $stmt = Database::instance()->pdo()->prepare(
            'SELECT * FROM users
             WHERE is_suspended = 0
               AND is_deleted = 0
               AND is_private = 0
               AND (lower(username) LIKE lower(:term) OR lower(display_name) LIKE lower(:term) OR lower(bio) LIKE lower(:term))
             ORDER BY follower_count DESC, username ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':term', $term);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Return who-to-follow suggestions.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function suggestions(?int $currentUserId, int $limit = 3): array
    {
        $db = Database::instance();
        if ($currentUserId === null) {
            $stmt = $db->pdo()->prepare('SELECT * FROM users WHERE is_suspended = 0 AND is_deleted = 0 AND is_private = 0 ORDER BY follower_count DESC, created_at DESC LIMIT :limit');
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        $stmt = $db->pdo()->prepare(
            'SELECT * FROM users
             WHERE id <> :id
               AND is_suspended = 0
               AND is_deleted = 0
               AND is_private = 0
               AND id NOT IN (SELECT following_id FROM follows WHERE follower_id = :id)
             ORDER BY follower_count DESC, created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':id', $currentUserId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Return featured active users for the public directory.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function featured(int $limit = 6): array
    {
        $stmt = Database::instance()->pdo()->prepare(
            'SELECT * FROM users
             WHERE is_suspended = 0
               AND is_deleted = 0
               AND is_system = 0
             ORDER BY is_admin DESC, is_verified DESC, follower_count DESC, tweet_count DESC, created_at ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, min(24, $limit)), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Return paginated active users for the public directory.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function directory(int $page, int $perPage = 30): array
    {
        $stmt = Database::instance()->pdo()->prepare(
            'SELECT * FROM users
             WHERE is_suspended = 0
               AND is_deleted = 0
               AND is_system = 0
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', max(1, min(100, $perPage)), \PDO::PARAM_INT);
        $stmt->bindValue(':offset', Helpers::offset($page, $perPage), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Return users following a user.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function followers(int $userId, int $page): array
    {
        $stmt = Database::instance()->pdo()->prepare(
            'SELECT u.* FROM follows f JOIN users u ON u.id = f.follower_id
             WHERE f.following_id = :id
             ORDER BY f.created_at DESC LIMIT 20 OFFSET :offset'
        );
        $stmt->bindValue(':id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * 20, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Return users a user follows.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function following(int $userId, int $page): array
    {
        $stmt = Database::instance()->pdo()->prepare(
            'SELECT u.* FROM follows f JOIN users u ON u.id = f.following_id
             WHERE f.follower_id = :id
             ORDER BY f.created_at DESC LIMIT 20 OFFSET :offset'
        );
        $stmt->bindValue(':id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * 20, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Set admin state and mirrored role for a user.
     */
    public static function setAdmin(int $id, bool $admin): void
    {
        if (!$admin && self::isOwnerId($id)) {
            throw new \RuntimeException('The Twitkey owner cannot have admin revoked.');
        }
        Database::instance()->execute(
            'UPDATE users SET is_admin = :admin, role = :role, updated_at = :updated_at WHERE id = :id',
            ['admin' => $admin ? 1 : 0, 'role' => $admin ? 'admin' : 'user', 'updated_at' => date('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    /**
     * Set or clear verification state.
     */
    public static function setVerification(int $id, ?string $type): void
    {
        if ($type !== null && !in_array($type, ['business', 'government'], true)) {
            throw new \InvalidArgumentException('Invalid verification type.');
        }
        if ($type === null && self::isOwnerId($id)) {
            throw new \RuntimeException('The Twitkey owner cannot have verification removed.');
        }
        Database::instance()->execute(
            'UPDATE users SET verified_type = :type, is_verified = :is_verified, auto_verified_by_affiliation = 0, updated_at = :updated_at WHERE id = :id',
            ['type' => $type, 'is_verified' => $type === null ? 0 : 1, 'updated_at' => date('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    /**
     * Set or clear the normal verified badge.
     */
    public static function setNormalVerified(int $id, bool $verified): void
    {
        if (!$verified && self::isOwnerId($id)) {
            throw new \RuntimeException('The Twitkey owner cannot have verification removed.');
        }
        Database::instance()->execute(
            'UPDATE users SET verified_type = NULL, is_verified = :is_verified, auto_verified_by_affiliation = 0, updated_at = :updated_at WHERE id = :id',
            ['is_verified' => $verified ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    /**
     * Set suspended state for a user.
     */
    public static function setSuspended(int $id, bool $suspended, string $reason = ''): void
    {
        $existing = self::find($id);
        if ($existing && (int)($existing['is_system'] ?? 0) === 1) {
            throw new \RuntimeException('System accounts cannot be suspended.');
        }
        if ($suspended && self::isOwnerRow($existing)) {
            throw new \RuntimeException('The Twitkey owner cannot be suspended.');
        }
        if (!$suspended && $existing && (int)($existing['is_deleted'] ?? 0) === 1) {
            throw new \RuntimeException('Deleted accounts cannot be unsuspended.');
        }
        $reason = $suspended ? Helpers::mbLimit(trim($reason), 240) : '';
        if ($suspended && $reason === '') {
            $reason = 'This account broke the Twitkey Terms of Service.';
        }
        Database::instance()->execute(
            'UPDATE users
             SET is_suspended = :suspended,
                 suspension_reason = :reason,
                 moderation_reason = :moderation_reason,
                 moderation_reviewed_at = :reviewed_at,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'suspended' => $suspended ? 1 : 0,
                'reason' => $reason,
                'moderation_reason' => $reason,
                'reviewed_at' => $suspended ? date('Y-m-d H:i:s') : null,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $id,
            ]
        );
    }

    /**
     * Soft-delete a user account so moderation context remains visible.
     */
    public static function delete(int $id, string $reason = ''): void
    {
        $user = self::find($id);
        if ($user && (int)($user['is_system'] ?? 0) === 1) {
            throw new \RuntimeException('System accounts cannot be deleted.');
        }
        if (self::isOwnerRow($user)) {
            throw new \RuntimeException('The Twitkey owner cannot be deleted.');
        }
        $reason = Helpers::mbLimit(trim($reason), 240);
        if ($reason === '') {
            $reason = 'This account was deleted after moderator review.';
        }
        Database::instance()->execute(
            'UPDATE users
             SET is_deleted = 1,
                 is_suspended = 1,
                 suspension_reason = :reason,
                 moderation_reason = :reason,
                 moderation_reviewed_at = :reviewed_at,
                 updated_at = :updated_at
             WHERE id = :id',
            ['reason' => $reason, 'reviewed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    /**
     * Change a username after validating uniqueness.
     */
    public static function changeUsername(int $id, string $username): void
    {
        $user = self::find($id);
        if ($user && (int)($user['is_system'] ?? 0) === 1) {
            throw new \RuntimeException('System account usernames cannot be changed.');
        }
        if (self::isOwnerRow($user)) {
            throw new \RuntimeException('The Twitkey owner username cannot be changed.');
        }
        $username = ltrim(trim($username), '@');
        if (!preg_match('/^[A-Za-z0-9_]{1,15}$/', $username)) {
            throw new \InvalidArgumentException('Username must be 1-15 letters, numbers, or underscores.');
        }
        $existing = self::findByUsername($username);
        if ($existing && (int)$existing['id'] !== $id) {
            throw new \InvalidArgumentException('That username is already taken.');
        }
        Database::instance()->execute(
            'UPDATE users SET username = :username, updated_at = :updated_at WHERE id = :id',
            ['username' => strtolower($username), 'updated_at' => date('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    /**
     * Reset a user's password to an administrator-provided value.
     */
    public static function setPassword(int $id, string $password, ?array $actor = null): void
    {
        $user = self::find($id);
        if (!$user) {
            throw new \InvalidArgumentException('User not found.');
        }
        if ($user && (int)($user['is_system'] ?? 0) === 1) {
            throw new \RuntimeException('System account passwords cannot be reset.');
        }
        if (self::isOwnerRow($user)) {
            throw new \RuntimeException('The Twitkey owner password cannot be reset from the admin panel.');
        }
        if ($user && (int)($user['is_admin'] ?? 0) === 1 && !self::isOwnerRow($actor)) {
            throw new \RuntimeException('Only the Twitkey owner can reset another administrator password.');
        }
        if (Helpers::mbLength($password) < 8) {
            throw new \InvalidArgumentException('New password must be at least 8 characters.');
        }
        Database::instance()->execute(
            'UPDATE users SET password = :password, updated_at = :updated_at WHERE id = :id',
            ['password' => password_hash($password, PASSWORD_BCRYPT), 'updated_at' => date('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    /**
     * Return the Community Notes system account.
     *
     * @return array<string, mixed>
     */
    public static function communityNotesBot(): array
    {
        $bot = self::findByUsername('CommunityNotes');
        if (!$bot) {
            throw new \RuntimeException('CommunityNotes system account is missing.');
        }
        return $bot;
    }

    /**
     * Return paginated users for the admin table.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function adminList(int $page): array
    {
        $stmt = Database::instance()->pdo()->prepare('SELECT * FROM users ORDER BY id DESC LIMIT 50 OFFSET :offset');
        $stmt->bindValue(':offset', ($page - 1) * 50, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
