<?php
declare(strict_types=1);

namespace Twitkey\Models;

use Twitkey\Core\Database;
use Twitkey\Core\Helpers;

final class Tweet
{
    /**
     * Create a tweet or reply and return the stored tweet row with user data.
     *
     * @return array<string, mixed>
     */
    public static function create(int $userId, string $body, ?int $replyToId = null, ?int $retweetOfId = null, array $metadata = []): array
    {
        $body = trim($body);
        if (Helpers::mbLength($body) > 140) {
            throw new \InvalidArgumentException('Tweets are limited to 140 characters.');
        }
        if ($body === '' && empty($metadata['media']) && empty($metadata['gif_url']) && empty($metadata['poll'])) {
            throw new \InvalidArgumentException('Tweet body, media, GIF, or poll is required.');
        }

        $db = Database::instance();
        return $db->transaction(static function () use ($db, $userId, $body, $replyToId, $retweetOfId, $metadata): array {
            $tweetId = self::insertTweet($db, $userId, $body, $replyToId, $retweetOfId, $metadata);
            return self::findWithUser($tweetId, true) ?? [];
        });
    }

    /**
     * Return the home timeline for a user.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function feedForUser(int $userId, int $page, ?int $lastId = null): array
    {
        $where = 't.is_deleted = 0 AND u.is_suspended = 0 AND ' . self::publishedWhere()
            . ' AND (t.user_id = :user_id OR t.user_id IN (SELECT following_id FROM follows WHERE follower_id = :user_id)'
            . ' OR t.id IN (SELECT rt.tweet_id FROM retweets rt WHERE rt.user_id = :user_id OR rt.user_id IN (SELECT following_id FROM follows WHERE follower_id = :user_id)))';
        $rows = self::feed($where, ['user_id' => $userId], $page, $lastId);
        self::hydrateTimelineReposts($rows, $userId);
        return $rows;
    }

    /**
     * Return newer home timeline tweets for polling.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function newerForUser(int $userId, int $sinceId): array
    {
        $where = 't.id > :since_id AND t.is_deleted = 0 AND u.is_suspended = 0 AND ' . self::publishedWhere()
            . ' AND (t.user_id = :user_id OR t.user_id IN (SELECT following_id FROM follows WHERE follower_id = :user_id)'
            . ' OR t.id IN (SELECT rt.tweet_id FROM retweets rt WHERE rt.user_id = :user_id OR rt.user_id IN (SELECT following_id FROM follows WHERE follower_id = :user_id)))';
        $rows = self::feed($where, ['since_id' => $sinceId, 'user_id' => $userId], 1, null, 20);
        self::hydrateTimelineReposts($rows, $userId);
        return $rows;
    }

    /**
     * Return the mutuals timeline (posts by you + mutual followers + reposts by mutuals).
     * Mutual = users with reciprocal follows (you follow them and they follow you).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function feedForMutuals(int $userId, int $page, ?int $lastId = null): array
    {
        $mutual = '(SELECT f.following_id FROM follows f JOIN follows b ON f.following_id = b.follower_id AND b.following_id = :user_id WHERE f.follower_id = :user_id)';
        $where = 't.is_deleted = 0 AND u.is_suspended = 0 AND ' . self::publishedWhere()
            . ' AND (t.user_id = :user_id OR t.user_id IN ' . $mutual
            . ' OR t.id IN (SELECT rt.tweet_id FROM retweets rt WHERE rt.user_id = :user_id OR rt.user_id IN ' . $mutual . '))';
        $rows = self::feed($where, ['user_id' => $userId], $page, $lastId);
        self::hydrateTimelineReposts($rows, $userId);
        return $rows;
    }

    /**
     * Return newer mutuals timeline tweets for polling.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function newerForMutuals(int $userId, int $sinceId): array
    {
        $mutual = '(SELECT f.following_id FROM follows f JOIN follows b ON f.following_id = b.follower_id AND b.following_id = :user_id WHERE f.follower_id = :user_id)';
        $where = 't.id > :since_id AND t.is_deleted = 0 AND u.is_suspended = 0 AND ' . self::publishedWhere()
            . ' AND (t.user_id = :user_id OR t.user_id IN ' . $mutual
            . ' OR t.id IN (SELECT rt.tweet_id FROM retweets rt WHERE rt.user_id = :user_id OR rt.user_id IN ' . $mutual . '))';
        $rows = self::feed($where, ['since_id' => $sinceId, 'user_id' => $userId], 1, null, 20);
        self::hydrateTimelineReposts($rows, $userId);
        return $rows;
    }

    /**
     * Return the public timeline.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function publicTimeline(int $page, ?int $lastId = null): array
    {
        return self::feed('t.is_deleted = 0 AND u.is_suspended = 0 AND u.is_private = 0 AND u.post_visibility = :visibility AND ' . self::publishedWhere(), ['visibility' => 'public'], $page, $lastId);
    }

    /**
     * Return newer public tweets for polling.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function newerPublic(int $sinceId): array
    {
        return self::feed(
            't.id > :since_id AND t.is_deleted = 0 AND u.is_suspended = 0 AND u.is_private = 0 AND u.post_visibility = :visibility AND ' . self::publishedWhere(),
            ['since_id' => $sinceId, 'visibility' => 'public'],
            1,
            null,
            20
        );
    }

    /**
     * Return tweets for a profile tab.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forProfile(int $userId, string $tab, int $page, bool $includeSuspended = false): array
    {
        [$where, $params] = self::profileWhere($userId, $tab);
        if (!$includeSuspended) {
            $where .= ' AND u.is_suspended = 0';
        }
        $rows = self::feed($where, $params, $page, null);
        if ($tab === 'tweets') {
            self::hydrateProfileReposts($rows, $userId);
        }
        return $rows;
    }

    /**
     * Return newer profile-tab tweets for polling.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function newerForProfile(int $userId, string $tab, int $sinceId, bool $includeSuspended = false): array
    {
        [$where, $params] = self::profileWhere($userId, $tab);
        $where = 't.id > :since_id AND ' . $where;
        $params['since_id'] = $sinceId;
        if (!$includeSuspended) {
            $where .= ' AND u.is_suspended = 0';
        }
        $rows = self::feed($where, $params, 1, null, 20);
        if ($tab === 'tweets') {
            self::hydrateProfileReposts($rows, $userId);
        }
        return $rows;
    }

    /**
     * Return tweets matching a search term.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $query, int $page, ?int $viewerId = null): array
    {
        $term = '%' . $query . '%';
        $params = ['term' => $term];
        $where = 't.is_deleted = 0 AND u.is_suspended = 0 AND ' . self::publishedWhere() . ' AND lower(t.body) LIKE lower(:term) AND ' . self::visibleWhere($viewerId);
        if ($viewerId !== null) {
            $params['viewer_id'] = $viewerId;
        }
        return self::feed($where, $params, $page, null);
    }

    /**
     * Return tweets mentioning a username.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function mentionsFor(string $username, int $page, ?int $viewerId = null): array
    {
        $term = '%@' . $username . '%';
        $params = ['term' => strtolower($term)];
        $where = 't.is_deleted = 0 AND u.is_suspended = 0 AND ' . self::publishedWhere() . ' AND lower(t.body) LIKE lower(:term) AND ' . self::visibleWhere($viewerId);
        if ($viewerId !== null) {
            $params['viewer_id'] = $viewerId;
        }
        return self::feed($where, $params, $page, null);
    }

    /**
     * Return newer mention tweets for polling.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function newerMentionsFor(string $username, int $sinceId, ?int $viewerId = null): array
    {
        $params = ['since_id' => $sinceId, 'term' => strtolower('%@' . $username . '%')];
        $where = 't.id > :since_id AND t.is_deleted = 0 AND u.is_suspended = 0 AND ' . self::publishedWhere() . ' AND lower(t.body) LIKE lower(:term) AND ' . self::visibleWhere($viewerId);
        if ($viewerId !== null) {
            $params['viewer_id'] = $viewerId;
        }
        return self::feed($where, $params, 1, null, 20);
    }

    /**
     * Find a tweet and eager-loaded author by id.
     *
     * @return array<string, mixed>|null
     */
    public static function findWithUser(int $id, bool $includeDeleted = false): ?array
    {
        $where = 't.id = :id';
        if (!$includeDeleted) {
            $where .= ' AND t.is_deleted = 0';
        }
        $rows = self::feed($where, ['id' => $id], 1, null, 1, true);
        return $rows[0] ?? null;
    }

    /**
     * True when a viewer can access a tweet row.
     *
     * @param array<string, mixed> $tweet
     * @param array<string, mixed>|null $viewer
     */
    public static function canBeViewedBy(array $tweet, ?array $viewer): bool
    {
        if ((int)($viewer['is_admin'] ?? 0) === 1 || ($viewer && (int)$viewer['id'] === (int)$tweet['user_id'])) {
            return true;
        }
        if ((int)($tweet['is_suspended'] ?? 0) === 1 || (int)($tweet['user_is_deleted'] ?? 0) === 1) {
            return false;
        }
        $followersOnly = (int)($tweet['is_private'] ?? 0) === 1 || ($tweet['post_visibility'] ?? 'public') === 'followers';
        if (!$followersOnly) {
            return true;
        }
        return $viewer !== null && Follow::isFollowing((int)$viewer['id'], (int)$tweet['user_id']);
    }

    /**
     * Return direct replies to a tweet in chronological order.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function repliesTo(int $tweetId, bool $includeDeleted = true): array
    {
        $where = 't.reply_to_id = :tweet_id AND ' . self::publishedWhere();
        if (!$includeDeleted) {
            $where .= ' AND t.is_deleted = 0';
        }
        $rows = self::feed($where, ['tweet_id' => $tweetId], 1, null, 200, true, 'ASC');
        return $rows;
    }

    /**
     * Return newer replies under a tweet for polling.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function newerRepliesTo(int $tweetId, int $sinceId): array
    {
        return self::feed(
            't.id > :since_id AND t.reply_to_id = :tweet_id AND t.is_deleted = 0 AND ' . self::publishedWhere(),
            ['since_id' => $sinceId, 'tweet_id' => $tweetId],
            1,
            null,
            50,
            true,
            'ASC'
        );
    }

    /**
     * Toggle a favorite and return new count/state.
     *
     * @return array{favorited:bool,count:int}
     */
    public static function toggleFavorite(int $userId, int $tweetId): array
    {
        $db = Database::instance();
        return $db->transaction(static function () use ($db, $userId, $tweetId): array {
            $tweet = self::findWithUser($tweetId);
            if (!$tweet) {
                throw new \InvalidArgumentException('Tweet not found.');
            }
            $viewer = User::find($userId);
            if (!self::canBeViewedBy($tweet, $viewer)) {
                throw new \RuntimeException('Forbidden.');
            }
            $existing = $db->one('SELECT id FROM favorites WHERE user_id = :user_id AND tweet_id = :tweet_id', ['user_id' => $userId, 'tweet_id' => $tweetId]);
            if ($existing) {
                $db->execute('DELETE FROM favorites WHERE id = :id', ['id' => (int)$existing['id']]);
                $db->execute('UPDATE tweets SET favorite_count = CASE WHEN favorite_count > 0 THEN favorite_count - 1 ELSE 0 END WHERE id = :id', ['id' => $tweetId]);
                $favorited = false;
            } else {
                $db->execute('INSERT INTO favorites (user_id, tweet_id) VALUES (:user_id, :tweet_id)', ['user_id' => $userId, 'tweet_id' => $tweetId]);
                $db->execute('UPDATE tweets SET favorite_count = favorite_count + 1 WHERE id = :id', ['id' => $tweetId]);
                Notification::create((int)$tweet['user_id'], $userId, 'favorite', $tweetId);
                $favorited = true;
            }
            $row = $db->one('SELECT favorite_count FROM tweets WHERE id = :id', ['id' => $tweetId]);
            return ['favorited' => $favorited, 'count' => (int)($row['favorite_count'] ?? 0)];
        });
    }

    /**
     * Retweet a tweet once for a user and return the new count/state.
     *
     * @return array{retweeted:bool,count:int}
     */
    public static function retweet(int $userId, int $tweetId): array
    {
        $db = Database::instance();
        return $db->transaction(static function () use ($db, $userId, $tweetId): array {
            $original = self::findWithUser($tweetId);
            if (!$original) {
                throw new \InvalidArgumentException('Tweet not found.');
            }
            $viewer = User::find($userId);
            if (!self::canBeViewedBy($original, $viewer)) {
                throw new \RuntimeException('Forbidden.');
            }
            if ((int)$original['user_id'] === $userId) {
                throw new \InvalidArgumentException('You cannot retweet your own post.');
            }
            $existing = $db->one('SELECT id FROM retweets WHERE user_id = :user_id AND tweet_id = :tweet_id', ['user_id' => $userId, 'tweet_id' => $tweetId]);
            if ($existing) {
                throw new \InvalidArgumentException('You already retweeted this.');
            }
            $db->execute('INSERT INTO retweets (user_id, tweet_id) VALUES (:user_id, :tweet_id)', ['user_id' => $userId, 'tweet_id' => $tweetId]);
            $db->execute('UPDATE tweets SET retweet_count = retweet_count + 1 WHERE id = :id', ['id' => $tweetId]);
            Notification::create((int)$original['user_id'], $userId, 'retweet', $tweetId);
            $row = $db->one('SELECT retweet_count FROM tweets WHERE id = :id', ['id' => $tweetId]);
            return ['retweeted' => true, 'count' => (int)($row['retweet_count'] ?? 0)];
        });
    }

    /**
     * Soft-delete a tweet if the actor is the owner or an admin.
     */
    public static function delete(int $tweetId, int $actorId, bool $isAdmin): void
    {
        $tweet = self::findWithUser($tweetId, true);
        if (!$tweet) {
            throw new \InvalidArgumentException('Tweet not found.');
        }
        if (!$isAdmin && (int)$tweet['user_id'] !== $actorId) {
            throw new \RuntimeException('Forbidden.');
        }
        $db = Database::instance();
        $db->transaction(static function () use ($db, $tweetId, $tweet): void {
            if ((int)$tweet['is_deleted'] === 0) {
                $db->execute('UPDATE tweets SET is_deleted = 1 WHERE id = :id', ['id' => $tweetId]);
                $db->execute('UPDATE users SET tweet_count = CASE WHEN tweet_count > 0 THEN tweet_count - 1 ELSE 0 END WHERE id = :id', ['id' => (int)$tweet['user_id']]);
            }
        });
    }

    /**
     * True when a user has favorited a tweet.
     */
    public static function isFavorited(?int $userId, int $tweetId): bool
    {
        if ($userId === null) {
            return false;
        }
        return Database::instance()->one(
            'SELECT id FROM favorites WHERE user_id = :user_id AND tweet_id = :tweet_id',
            ['user_id' => $userId, 'tweet_id' => $tweetId]
        ) !== null;
    }

    /**
     * True when a user has retweeted a tweet.
     */
    public static function isRetweeted(?int $userId, int $tweetId): bool
    {
        if ($userId === null) {
            return false;
        }
        return Database::instance()->one(
            'SELECT id FROM retweets WHERE user_id = :user_id AND tweet_id = :tweet_id',
            ['user_id' => $userId, 'tweet_id' => $tweetId]
        ) !== null;
    }

    /**
     * Vote for a poll option, replacing the user's previous vote in the same poll.
     */
    public static function votePoll(int $tweetId, int $optionId, int $userId): void
    {
        $db = Database::instance();
        $poll = $db->one(
            'SELECT p.*, t.scheduled_at FROM polls p JOIN tweets t ON t.id = p.tweet_id JOIN poll_options po ON po.poll_id = p.id WHERE p.tweet_id = :tweet_id AND po.id = :option_id AND t.is_deleted = 0',
            ['tweet_id' => $tweetId, 'option_id' => $optionId]
        );
        if (!$poll) {
            throw new \InvalidArgumentException('Poll option not found.');
        }
        if (!empty($poll['scheduled_at']) && strtotime((string)$poll['scheduled_at']) > time()) {
            throw new \RuntimeException('This poll is not open yet.');
        }
        if (!empty($poll['closes_at']) && strtotime((string)$poll['closes_at']) < time()) {
            throw new \RuntimeException('This poll is closed.');
        }

        $db->transaction(static function () use ($db, $poll, $optionId, $userId): void {
            $db->execute('DELETE FROM poll_votes WHERE poll_id = :poll_id AND user_id = :user_id', ['poll_id' => (int)$poll['id'], 'user_id' => $userId]);
            $db->execute('INSERT INTO poll_votes (poll_id, option_id, user_id) VALUES (:poll_id, :option_id, :user_id)', ['poll_id' => (int)$poll['id'], 'option_id' => $optionId, 'user_id' => $userId]);
        });
    }

    /**
     * Return visible tweet rows by id for realtime poll refreshes.
     *
     * @param array<int, int> $tweetIds
     * @param array<string, mixed>|null $viewer
     * @return array<int, array<string, mixed>>
     */
    public static function visibleRowsByIds(array $tweetIds, ?array $viewer): array
    {
        $tweetIds = array_values(array_unique(array_filter(array_map('intval', $tweetIds), static fn(int $id): bool => $id > 0)));
        if ($tweetIds === []) {
            return [];
        }
        $params = [];
        $placeholders = [];
        foreach ($tweetIds as $index => $tweetId) {
            $key = 'id' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $tweetId;
        }
        $rows = self::feed('t.id IN (' . implode(',', $placeholders) . ') AND t.is_deleted = 0 AND ' . self::publishedWhere(), $params, 1, null, count($tweetIds), true);
        return array_values(array_filter($rows, static fn(array $row): bool => self::canBeViewedBy($row, $viewer)));
    }

    /**
     * Return recent tweets for the admin table.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function adminList(int $page): array
    {
        return self::feed('1 = 1', [], $page, null, 50, true);
    }

    /**
     * Return profile interaction analytics for posts owned by a user.
     *
     * @return array{summary:array<string, int>,recent:array<int, array<string, mixed>>}
     */
    public static function analyticsForUser(int $userId): array
    {
        $db = Database::instance();
        $summary = $db->one(
            'SELECT COUNT(*) AS posts,
                    COALESCE(SUM(favorite_count), 0) AS favorites,
                    COALESCE(SUM(retweet_count), 0) AS reposts,
                    COALESCE(SUM(reply_count), 0) AS replies
             FROM tweets
             WHERE user_id = :user_id
               AND is_deleted = 0
               AND retweet_of_id IS NULL',
            ['user_id' => $userId]
        ) ?? [];
        $pollVotes = $db->one(
            'SELECT COUNT(*) AS count
             FROM poll_votes pv
             JOIN polls p ON p.id = pv.poll_id
             JOIN tweets t ON t.id = p.tweet_id
             WHERE t.user_id = :user_id
               AND t.is_deleted = 0',
            ['user_id' => $userId]
        );

        $recent = [];
        foreach ($db->all(
            'SELECT f.created_at, :type AS type, t.id AS tweet_id, t.body AS tweet_body,
                    u.id AS actor_id, u.username, u.display_name, u.avatar, u.is_admin, u.is_system, u.is_verified, u.is_private, u.verified_type
             FROM favorites f
             JOIN tweets t ON t.id = f.tweet_id
             JOIN users u ON u.id = f.user_id
             WHERE t.user_id = :user_id AND t.is_deleted = 0
             ORDER BY f.created_at DESC LIMIT 20',
            ['type' => 'favorite', 'user_id' => $userId]
        ) as $row) {
            $recent[] = $row;
        }
        foreach ($db->all(
            'SELECT rt.created_at, :type AS type, t.id AS tweet_id, t.body AS tweet_body,
                    u.id AS actor_id, u.username, u.display_name, u.avatar, u.is_admin, u.is_system, u.is_verified, u.is_private, u.verified_type
             FROM retweets rt
             JOIN tweets t ON t.id = rt.tweet_id
             JOIN users u ON u.id = rt.user_id
             WHERE t.user_id = :user_id AND t.is_deleted = 0
             ORDER BY rt.created_at DESC LIMIT 20',
            ['type' => 'repost', 'user_id' => $userId]
        ) as $row) {
            $recent[] = $row;
        }
        foreach ($db->all(
            'SELECT r.created_at, :type AS type, p.id AS tweet_id, r.body AS tweet_body,
                    u.id AS actor_id, u.username, u.display_name, u.avatar, u.is_admin, u.is_system, u.is_verified, u.is_private, u.verified_type
             FROM tweets r
             JOIN tweets p ON p.id = r.reply_to_id
             JOIN users u ON u.id = r.user_id
             WHERE p.user_id = :user_id AND r.is_deleted = 0 AND p.is_deleted = 0
             ORDER BY r.created_at DESC LIMIT 20',
            ['type' => 'reply', 'user_id' => $userId]
        ) as $row) {
            $recent[] = $row;
        }
        foreach ($db->all(
            'SELECT pv.created_at, :type AS type, t.id AS tweet_id, po.body AS tweet_body,
                    u.id AS actor_id, u.username, u.display_name, u.avatar, u.is_admin, u.is_system, u.is_verified, u.is_private, u.verified_type
             FROM poll_votes pv
             JOIN polls p ON p.id = pv.poll_id
             JOIN poll_options po ON po.id = pv.option_id
             JOIN tweets t ON t.id = p.tweet_id
             JOIN users u ON u.id = pv.user_id
             WHERE t.user_id = :user_id AND t.is_deleted = 0
             ORDER BY pv.created_at DESC LIMIT 20',
            ['type' => 'poll_vote', 'user_id' => $userId]
        ) as $row) {
            $recent[] = $row;
        }

        usort($recent, static fn(array $a, array $b): int => strcmp((string)$b['created_at'], (string)$a['created_at']));
        $recent = array_slice($recent, 0, 30);

        return [
            'summary' => [
                'posts' => (int)($summary['posts'] ?? 0),
                'favorites' => (int)($summary['favorites'] ?? 0),
                'reposts' => (int)($summary['reposts'] ?? 0),
                'replies' => (int)($summary['replies'] ?? 0),
                'poll_votes' => (int)($pollVotes['count'] ?? 0),
            ],
            'recent' => $recent,
        ];
    }

    /**
     * Fetch tweet rows with eager-loaded users and approved note preview.
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private static function feed(string $where, array $params, int $page, ?int $lastId, int $limit = 20, bool $includeSuspended = false, string $direction = 'DESC'): array
    {
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        if ($lastId !== null) {
            $where .= ' AND t.id < :last_id';
            $params['last_id'] = $lastId;
        }
        if (!$includeSuspended && !str_contains($where, 'u.is_suspended')) {
            $where .= ' AND u.is_suspended = 0';
        }
        if (!$includeSuspended && !str_contains($where, 'u.is_deleted')) {
            $where .= ' AND u.is_deleted = 0';
        }
        if (!str_contains($where, 't.retweet_of_id')) {
            $where .= ' AND t.retweet_of_id IS NULL';
        }

        $stmt = Database::instance()->pdo()->prepare(
            "SELECT t.*,
                    u.username, u.display_name, u.email, u.bio, u.location, u.website, u.avatar, u.background,
                    u.role, u.verified_type, u.is_verified, u.is_admin, u.is_system, u.is_suspended, u.is_deleted AS user_is_deleted, u.is_private, u.follow_privacy, u.post_visibility, u.dm_privacy, u.follower_count, u.following_count, u.tweet_count,
                    u.created_at AS user_created_at,
                    p.username AS reply_parent_username,
                    pt.body AS reply_parent_body,
                    pt.is_deleted AS reply_parent_deleted,
                    (SELECT cn.body FROM community_notes cn WHERE cn.tweet_id = t.id AND cn.status = 'approved' ORDER BY cn.helpful_votes DESC, cn.id ASC LIMIT 1) AS approved_note_body,
                    (SELECT cn.id FROM community_notes cn WHERE cn.tweet_id = t.id AND cn.status = 'approved' ORDER BY cn.helpful_votes DESC, cn.id ASC LIMIT 1) AS approved_note_id
             FROM tweets t
             JOIN users u ON u.id = t.user_id
             LEFT JOIN tweets pt ON pt.id = t.reply_to_id
             LEFT JOIN users p ON p.id = pt.user_id
             WHERE {$where}
             ORDER BY t.id {$direction}
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        self::hydrateExtras($rows);
        return $rows;
    }

    /**
     * Insert a tweet inside an existing transaction and perform related updates.
     */
    private static function insertTweet(Database $db, int $userId, string $body, ?int $replyToId, ?int $retweetOfId, array $metadata = []): int
    {
        $db->execute(
            'INSERT INTO tweets (user_id, body, reply_to_id, retweet_of_id, scheduled_at, location_label, location_lat, location_lng, gif_url)
             VALUES (:user_id, :body, :reply_to_id, :retweet_of_id, :scheduled_at, :location_label, :location_lat, :location_lng, :gif_url)',
            [
                'user_id' => $userId,
                'body' => $body,
                'reply_to_id' => $replyToId,
                'retweet_of_id' => $retweetOfId,
                'scheduled_at' => $metadata['scheduled_at'] ?? null,
                'location_label' => $metadata['location_label'] ?? null,
                'location_lat' => $metadata['location_lat'] ?? null,
                'location_lng' => $metadata['location_lng'] ?? null,
                'gif_url' => $metadata['gif_url'] ?? null,
            ]
        );
        $tweetId = $db->lastInsertId();
        $isFutureScheduled = !empty($metadata['scheduled_at']) && strtotime((string)$metadata['scheduled_at']) > time();
        $db->execute('UPDATE users SET tweet_count = tweet_count + 1 WHERE id = :id', ['id' => $userId]);

        foreach (($metadata['media'] ?? []) as $media) {
            $db->execute(
                'INSERT INTO tweet_media (tweet_id, file_name, mime_type) VALUES (:tweet_id, :file_name, :mime_type)',
                ['tweet_id' => $tweetId, 'file_name' => $media['file_name'], 'mime_type' => $media['mime_type']]
            );
        }
        if (!empty($metadata['poll'])) {
            $poll = $metadata['poll'];
            $db->execute(
                'INSERT INTO polls (tweet_id, question, closes_at) VALUES (:tweet_id, :question, :closes_at)',
                ['tweet_id' => $tweetId, 'question' => $poll['question'], 'closes_at' => $poll['closes_at'] ?? null]
            );
            $pollId = $db->lastInsertId();
            foreach ($poll['options'] as $position => $option) {
                $db->execute(
                    'INSERT INTO poll_options (poll_id, body, position) VALUES (:poll_id, :body, :position)',
                    ['poll_id' => $pollId, 'body' => $option, 'position' => $position + 1]
                );
            }
        }

        if ($replyToId !== null) {
            $parent = self::findWithUser($replyToId, true);
            if ($parent) {
                $db->execute('UPDATE tweets SET reply_count = reply_count + 1 WHERE id = :id', ['id' => $replyToId]);
                Notification::create((int)$parent['user_id'], $userId, 'reply', $tweetId);
            }
        }

        self::indexHashtags($tweetId, $body);
        if (!$isFutureScheduled) {
            self::notifyMentions($tweetId, $userId, $body);
        }
        return $tweetId;
    }

    /**
     * Attach media and poll data to tweet rows with batched queries.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private static function hydrateExtras(array &$rows): void
    {
        if ($rows === []) {
            return;
        }

        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db = Database::instance();

        $mediaByTweet = [];
        foreach ($db->all("SELECT * FROM tweet_media WHERE tweet_id IN ({$placeholders}) ORDER BY id ASC", $ids) as $media) {
            $mediaByTweet[(int)$media['tweet_id']][] = $media;
        }

        $pollsByTweet = [];
        $polls = $db->all("SELECT * FROM polls WHERE tweet_id IN ({$placeholders})", $ids);
        foreach ($polls as $poll) {
            $options = $db->all(
                'SELECT po.*, COUNT(pv.id) AS vote_count
                 FROM poll_options po
                 LEFT JOIN poll_votes pv ON pv.option_id = po.id
                 WHERE po.poll_id = :poll_id
                 GROUP BY po.id
                 ORDER BY po.position ASC',
                ['poll_id' => (int)$poll['id']]
            );
            $total = 0;
            foreach ($options as $option) {
                $total += (int)$option['vote_count'];
            }
            $poll['options'] = $options;
            $poll['total_votes'] = $total;
            $pollsByTweet[(int)$poll['tweet_id']] = $poll;
        }

        foreach ($rows as &$row) {
            $id = (int)$row['id'];
            $row['media'] = $mediaByTweet[$id] ?? [];
            $row['poll'] = $pollsByTweet[$id] ?? null;
        }
    }

    /**
     * Annotate profile rows that are present because the profile owner reposted them.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private static function hydrateProfileReposts(array &$rows, int $profileUserId): void
    {
        if ($rows === []) {
            return;
        }
        $profile = User::find($profileUserId);
        if (!$profile) {
            return;
        }
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$profileUserId], $ids);
        $retweets = Database::instance()->all(
            "SELECT tweet_id, created_at FROM retweets WHERE user_id = ? AND tweet_id IN ({$placeholders})",
            $params
        );
        $repostedAt = [];
        foreach ($retweets as $retweet) {
            $repostedAt[(int)$retweet['tweet_id']] = (string)$retweet['created_at'];
        }
        foreach ($rows as &$row) {
            $id = (int)$row['id'];
            if (isset($repostedAt[$id]) && (int)$row['user_id'] !== $profileUserId) {
                $row['reposted_by_id'] = $profileUserId;
                $row['reposted_by_username'] = $profile['username'];
                $row['reposted_by_display_name'] = $profile['display_name'];
                $row['reposted_at'] = $repostedAt[$id];
            }
            $row['_profile_activity_at'] = $repostedAt[$id] ?? (string)$row['created_at'];
        }
        usort($rows, static function (array $a, array $b): int {
            return strcmp((string)($b['_profile_activity_at'] ?? $b['created_at']), (string)($a['_profile_activity_at'] ?? $a['created_at']));
        });
    }

    /**
     * Annotate timeline rows that are present because a followed/current account reposted them.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private static function hydrateTimelineReposts(array &$rows, int $viewerId): void
    {
        if ($rows === []) {
            return;
        }
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $placeholders = [];
        $params = ['viewer_id' => $viewerId];
        foreach ($ids as $index => $id) {
            $key = 'tweet_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $retweets = Database::instance()->all(
            "SELECT rt.tweet_id, rt.user_id, rt.created_at, u.username, u.display_name
             FROM retweets rt
             JOIN users u ON u.id = rt.user_id
             WHERE rt.tweet_id IN (" . implode(',', $placeholders) . ")
               AND (rt.user_id = :viewer_id OR rt.user_id IN (SELECT following_id FROM follows WHERE follower_id = :viewer_id))
             ORDER BY rt.created_at DESC",
            $params
        );
        $byTweet = [];
        foreach ($retweets as $retweet) {
            $tweetId = (int)$retweet['tweet_id'];
            if (!isset($byTweet[$tweetId]) || (int)$retweet['user_id'] === $viewerId) {
                $byTweet[$tweetId] = $retweet;
            }
        }
        foreach ($rows as &$row) {
            $id = (int)$row['id'];
            if (isset($byTweet[$id]) && (int)$row['user_id'] !== (int)$byTweet[$id]['user_id']) {
                $row['reposted_by_id'] = (int)$byTweet[$id]['user_id'];
                $row['reposted_by_username'] = (string)$byTweet[$id]['username'];
                $row['reposted_by_display_name'] = (string)$byTweet[$id]['display_name'];
                $row['reposted_at'] = (string)$byTweet[$id]['created_at'];
            }
        }
    }

    /**
     * SQL condition for scheduled tweets that should be visible now.
     */
    private static function publishedWhere(): string
    {
        return Database::instance()->isMysql()
            ? '(t.scheduled_at IS NULL OR t.scheduled_at <= NOW())'
            : "(t.scheduled_at IS NULL OR t.scheduled_at <= datetime('now'))";
    }

    /**
     * SQL condition for tweets visible to a viewer.
     */
    private static function visibleWhere(?int $viewerId): string
    {
        if ($viewerId === null) {
            return '(u.is_private = 0 AND u.post_visibility = \'public\')';
        }
        return '(u.id = :viewer_id OR (u.is_private = 0 AND u.post_visibility = \'public\') OR u.id IN (SELECT following_id FROM follows WHERE follower_id = :viewer_id))';
    }

    /**
     * Build the profile-tab WHERE clause.
     *
     * @return array{0:string,1:array<string, mixed>}
     */
    private static function profileWhere(int $userId, string $tab): array
    {
        $params = ['user_id' => $userId];
        if ($tab === 'favorites') {
            return ['t.id IN (SELECT tweet_id FROM favorites WHERE user_id = :user_id) AND t.is_deleted = 0 AND ' . self::publishedWhere(), $params];
        }
        if ($tab === 'replies') {
            return ['t.user_id = :user_id AND t.reply_to_id IS NOT NULL AND t.is_deleted = 0 AND ' . self::publishedWhere(), $params];
        }
        return ['(t.user_id = :user_id OR t.id IN (SELECT tweet_id FROM retweets WHERE user_id = :user_id)) AND t.is_deleted = 0 AND ' . self::publishedWhere(), $params];
    }

    /**
     * Index hashtags found in a tweet.
     */
    private static function indexHashtags(int $tweetId, string $body): void
    {
        if (preg_match_all('/(?<![\w#])#([A-Za-z0-9_]{1,60})/', $body, $matches) !== 1) {
            return;
        }
        $db = Database::instance();
        foreach (array_unique(array_map('strtolower', $matches[1])) as $tag) {
            $existing = $db->one('SELECT id FROM hashtags WHERE tag = :tag', ['tag' => $tag]);
            if (!$existing) {
                $db->execute('INSERT INTO hashtags (tag) VALUES (:tag)', ['tag' => $tag]);
                $hashtagId = $db->lastInsertId();
            } else {
                $hashtagId = (int)$existing['id'];
            }
            if ($db->isMysql()) {
                $db->execute(
                    'INSERT IGNORE INTO tweet_hashtags (tweet_id, hashtag_id) VALUES (:tweet_id, :hashtag_id)',
                    ['tweet_id' => $tweetId, 'hashtag_id' => $hashtagId]
                );
            } else {
                $db->execute(
                    'INSERT OR IGNORE INTO tweet_hashtags (tweet_id, hashtag_id) VALUES (:tweet_id, :hashtag_id)',
                    ['tweet_id' => $tweetId, 'hashtag_id' => $hashtagId]
                );
            }
        }
    }

    /**
     * Notify mentioned users once per tweet.
     */
    private static function notifyMentions(int $tweetId, int $actorId, string $body): void
    {
        if (preg_match_all('/(?<![\w@])@([A-Za-z0-9_]{1,15})/', $body, $matches) !== 1) {
            return;
        }
        foreach (array_unique(array_map('strtolower', $matches[1])) as $username) {
            $user = User::findByUsername($username);
            if ($user) {
                Notification::create((int)$user['id'], $actorId, 'mention', $tweetId);
            }
        }
    }
}
