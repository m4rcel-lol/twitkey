<?php
declare(strict_types=1);

namespace Twitkey\Models;

use Twitkey\Core\Database;
use Twitkey\Core\Helpers;

final class CommunityNote
{
    /**
     * Add a pending note to a tweet.
     */
    public static function add(int $tweetId, int $authorId, string $body): void
    {
        $body = trim($body);
        if ($body === '' || Helpers::mbLength($body) > 500) {
            throw new \InvalidArgumentException('Community notes must be between 1 and 500 characters.');
        }
        if (!Tweet::findWithUser($tweetId, true)) {
            throw new \InvalidArgumentException('Tweet not found.');
        }
        Database::instance()->execute(
            'INSERT INTO community_notes (tweet_id, author_id, body) VALUES (:tweet_id, :author_id, :body)',
            ['tweet_id' => $tweetId, 'author_id' => $authorId, 'body' => $body]
        );
    }

    /**
     * Add an immediately approved administrator-created note.
     */
    public static function addApproved(int $tweetId, int $authorId, string $body, int $adminId): void
    {
        $body = trim($body);
        if ($body === '' || Helpers::mbLength($body) > 500) {
            throw new \InvalidArgumentException('Community notes must be between 1 and 500 characters.');
        }
        if (!Tweet::findWithUser($tweetId, true)) {
            throw new \InvalidArgumentException('Tweet not found.');
        }
        Database::instance()->execute(
            'INSERT INTO community_notes (tweet_id, author_id, body, status, reviewed_by, reviewed_at)
             VALUES (:tweet_id, :author_id, :body, :status, :reviewed_by, :reviewed_at)',
            [
                'tweet_id' => $tweetId,
                'author_id' => $authorId,
                'body' => $body,
                'status' => 'approved',
                'reviewed_by' => $adminId,
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Return approved note for a tweet.
     *
     * @return array<string, mixed>|null
     */
    public static function approvedForTweet(int $tweetId): ?array
    {
        return Database::instance()->one(
            'SELECT cn.*, u.username, u.display_name, u.avatar, u.is_admin, u.verified_type
             FROM community_notes cn
             JOIN users u ON u.id = cn.author_id
             WHERE cn.tweet_id = :tweet_id AND cn.status = :status
             ORDER BY cn.helpful_votes DESC, cn.id ASC
             LIMIT 1',
            ['tweet_id' => $tweetId, 'status' => 'approved']
        );
    }

    /**
     * Return pending notes for eligible users to review.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pending(int $page): array
    {
        $stmt = Database::instance()->pdo()->prepare(
            'SELECT cn.*, t.body AS tweet_body, a.username AS author_username, u.username AS tweet_username
             FROM community_notes cn
             JOIN tweets t ON t.id = cn.tweet_id
             JOIN users a ON a.id = cn.author_id
             JOIN users u ON u.id = t.user_id
             WHERE cn.status = :status AND t.is_deleted = 0 AND u.is_suspended = 0 AND u.is_deleted = 0
             ORDER BY cn.created_at ASC
             LIMIT 20 OFFSET :offset'
        );
        $stmt->bindValue(':status', 'pending');
        $stmt->bindValue(':offset', ($page - 1) * 20, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Record or change a helpful/unhelpful vote and auto-moderate.
     */
    public static function vote(int $noteId, int $userId, string $vote): void
    {
        if (!in_array($vote, ['helpful', 'unhelpful'], true)) {
            throw new \InvalidArgumentException('Invalid vote.');
        }
        $db = Database::instance();
        $db->transaction(static function () use ($db, $noteId, $userId, $vote): void {
            $note = $db->one('SELECT id, author_id, status FROM community_notes WHERE id = :id', ['id' => $noteId]);
            if (!$note) {
                throw new \InvalidArgumentException('Community Note not found.');
            }
            if ((string)$note['status'] !== 'pending') {
                throw new \RuntimeException('Voting is closed for this Community Note.');
            }
            if ((int)$note['author_id'] === $userId) {
                throw new \RuntimeException('You cannot vote on your own Community Note.');
            }
            $existing = $db->one('SELECT vote FROM community_note_votes WHERE note_id = :note_id AND user_id = :user_id', ['note_id' => $noteId, 'user_id' => $userId]);
            if ($existing && $existing['vote'] === $vote) {
                return;
            }
            if ($existing) {
                $db->execute('UPDATE community_note_votes SET vote = :vote WHERE note_id = :note_id AND user_id = :user_id', ['vote' => $vote, 'note_id' => $noteId, 'user_id' => $userId]);
            } else {
                $db->execute('INSERT INTO community_note_votes (note_id, user_id, vote) VALUES (:note_id, :user_id, :vote)', ['note_id' => $noteId, 'user_id' => $userId, 'vote' => $vote]);
            }
        });
        self::recountVotes($noteId);
        self::autoModerate($noteId);
    }

    /**
     * Apply automatic approval/rejection rules to pending notes.
     */
    public static function autoModerate(?int $noteId = null): void
    {
        $db = Database::instance();
        if ($noteId !== null) {
            self::recountVotes($noteId);
        }
        $where = 'status = :pending';
        $params = ['pending' => 'pending'];
        if ($noteId !== null) {
            $where .= ' AND id = :id';
            $params['id'] = $noteId;
        }
        $db->execute(
            'UPDATE community_notes
             SET status = :approved, reviewed_at = :reviewed_at
             WHERE ' . $where . ' AND helpful_votes >= 5 AND (helpful_votes + unhelpful_votes) >= 5 AND helpful_votes > (unhelpful_votes * 2)',
            ['approved' => 'approved', 'reviewed_at' => date('Y-m-d H:i:s')] + $params
        );
        $db->execute(
            'UPDATE community_notes
             SET status = :rejected, reviewed_at = :reviewed_at
             WHERE ' . $where . ' AND unhelpful_votes >= 10 AND (helpful_votes + unhelpful_votes) >= 10 AND unhelpful_votes > (helpful_votes * 2)',
            ['rejected' => 'rejected', 'reviewed_at' => date('Y-m-d H:i:s')] + $params
        );
    }

    /**
     * Rebuild denormalized vote counters from the vote table.
     */
    public static function recountVotes(int $noteId): void
    {
        $counts = Database::instance()->one(
            "SELECT
                SUM(CASE WHEN vote = 'helpful' THEN 1 ELSE 0 END) AS helpful,
                SUM(CASE WHEN vote = 'unhelpful' THEN 1 ELSE 0 END) AS unhelpful
             FROM community_note_votes
             WHERE note_id = :note_id",
            ['note_id' => $noteId]
        ) ?? [];
        Database::instance()->execute(
            'UPDATE community_notes SET helpful_votes = :helpful, unhelpful_votes = :unhelpful WHERE id = :id',
            ['helpful' => (int)($counts['helpful'] ?? 0), 'unhelpful' => (int)($counts['unhelpful'] ?? 0), 'id' => $noteId]
        );
    }

    /**
     * Return all notes for the admin panel.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function adminList(int $page): array
    {
        $stmt = Database::instance()->pdo()->prepare(
            'SELECT cn.*, t.body AS tweet_body, a.username AS author_username, u.username AS tweet_username
             FROM community_notes cn
             JOIN tweets t ON t.id = cn.tweet_id
             JOIN users a ON a.id = cn.author_id
             JOIN users u ON u.id = t.user_id
             ORDER BY cn.created_at DESC
             LIMIT 50 OFFSET :offset'
        );
        $stmt->bindValue(':offset', ($page - 1) * 50, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Set note review status.
     */
    public static function setStatus(int $noteId, string $status, int $adminId): void
    {
        if (!in_array($status, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('Invalid note status.');
        }
        Database::instance()->execute(
            'UPDATE community_notes SET status = :status, reviewed_by = :admin_id, reviewed_at = :reviewed_at WHERE id = :id',
            ['status' => $status, 'admin_id' => $adminId, 'reviewed_at' => date('Y-m-d H:i:s'), 'id' => $noteId]
        );
    }

    /**
     * Delete a community note.
     */
    public static function delete(int $noteId): void
    {
        Database::instance()->execute('DELETE FROM community_notes WHERE id = :id', ['id' => $noteId]);
    }

    /**
     * Remove approved notes from a tweet by rejecting them.
     */
    public static function rejectForTweet(int $tweetId, int $adminId): void
    {
        Database::instance()->execute(
            'UPDATE community_notes SET status = :status, reviewed_by = :admin_id, reviewed_at = :reviewed_at WHERE tweet_id = :tweet_id AND status = :approved',
            ['status' => 'rejected', 'admin_id' => $adminId, 'reviewed_at' => date('Y-m-d H:i:s'), 'tweet_id' => $tweetId, 'approved' => 'approved']
        );
    }

    /**
     * Flag a note as misleading.
     */
    public static function flag(int $noteId, int $userId, string $reason = ''): void
    {
        $db = Database::instance();
        $sql = $db->isMysql()
            ? 'INSERT IGNORE INTO community_note_flags (note_id, user_id, reason) VALUES (:note_id, :user_id, :reason)'
            : 'INSERT OR IGNORE INTO community_note_flags (note_id, user_id, reason) VALUES (:note_id, :user_id, :reason)';
        $db->execute($sql, ['note_id' => $noteId, 'user_id' => $userId, 'reason' => $reason]);
        $admins = $db->all('SELECT id FROM users WHERE is_admin = 1');
        foreach ($admins as $admin) {
            Notification::create((int)$admin['id'], $userId, 'note_flag');
        }
    }
}
