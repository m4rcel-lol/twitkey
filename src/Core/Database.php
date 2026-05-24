<?php
declare(strict_types=1);

namespace Twitkey\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?self $instance = null;
    private PDO $pdo;
    private string $driver;

    /**
     * Create the PDO connection and install the schema on first boot.
     */
    private function __construct()
    {
        $this->driver = strtolower((string)($_ENV['DB_DRIVER'] ?? getenv('DB_DRIVER') ?: 'sqlite'));
        $this->ensureDataDirectories();

        if ($this->driver === 'mysql') {
            $host = (string)($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'mysql');
            $port = (string)($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306');
            $name = (string)($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'twitkey');
            $user = (string)($_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'twitkey');
            $pass = (string)($_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '');
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $user, $pass, $this->options());
        } else {
            $path = (string)($_ENV['DB_PATH'] ?? getenv('DB_PATH') ?: $this->defaultSqlitePath());
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $this->pdo = new PDO('sqlite:' . $path, null, null, $this->options());
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }

        if (!$this->hasTable('users')) {
            $this->installSchema();
            $installed = $this->dataDir() . '/.installed';
            if (!is_file($installed)) {
                file_put_contents($installed, date(DATE_ATOM));
            }
        }
        $this->migrateSchema();
        $this->ensureOwnerAccountState();
        $this->ensureCommunityNotesBot();
    }

    /**
     * Return the singleton database wrapper.
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Return the underlying PDO connection.
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * True when the active connection is MySQL.
     */
    public function isMysql(): bool
    {
        return $this->driver === 'mysql';
    }

    /**
     * Fetch one row from a prepared query.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $this->bind($stmt, $key, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Fetch all rows from a prepared query.
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $this->bind($stmt, $key, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Execute a prepared mutation query.
     *
     * @param array<string, mixed> $params
     */
    public function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $this->bind($stmt, $key, $value);
        }
        return $stmt->execute();
    }

    /**
     * Bind a value with an explicit PDO type.
     */
    private function bind(\PDOStatement $stmt, int|string $key, mixed $value): void
    {
        $param = is_int($key) ? $key + 1 : ':' . ltrim((string)$key, ':');
        $type = match (true) {
            $value === null => PDO::PARAM_NULL,
            is_bool($value) => PDO::PARAM_BOOL,
            is_int($value) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        };
        $stmt->bindValue($param, $value, $type);
    }

    /**
     * Return the last inserted primary key.
     */
    public function lastInsertId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Execute a callback inside a transaction.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Return the writable data directory.
     */
    public function dataDir(): string
    {
        $path = (string)($_ENV['DATA_DIR'] ?? getenv('DATA_DIR') ?: '/data');
        if (!is_dir($path) && defined('TWITKEY_ROOT')) {
            $fallback = TWITKEY_ROOT . '/data';
            if (is_dir($fallback) || mkdir($fallback, 0777, true)) {
                return $fallback;
            }
        }
        return $path;
    }

    /**
     * Ensure expected persistent folders exist.
     */
    private function ensureDataDirectories(): void
    {
        foreach ([$this->dataDir(), $this->dataDir() . '/avatars', $this->dataDir() . '/uploads', $this->dataDir() . '/cache'] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }
    }

    /**
     * Return conservative PDO options for production use.
     *
     * @return array<int, mixed>
     */
    private function options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => $this->driver === 'mysql',
        ];
    }

    /**
     * Resolve the default SQLite path.
     */
    private function defaultSqlitePath(): string
    {
        return $this->dataDir() . '/twitkey.db';
    }

    /**
     * Check whether a table already exists.
     */
    private function hasTable(string $table): bool
    {
        if ($this->isMysql()) {
            $row = $this->one('SHOW TABLES LIKE :table', ['table' => $table]);
            return $row !== null;
        }
        $row = $this->one("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table", ['table' => $table]);
        return $row !== null;
    }

    /**
     * Install the schema from schema.sql, with light type adaptation for MySQL.
     */
    private function installSchema(): void
    {
        $schemaPath = TWITKEY_ROOT . '/schema.sql';
        $sql = (string)file_get_contents($schemaPath);
        if ($this->isMysql()) {
            $sql = $this->mysqlSchema($sql);
        }

        foreach ($this->splitStatements($sql) as $statement) {
            $trimmed = trim($statement);
            if ($trimmed !== '') {
                $this->pdo->exec($trimmed);
            }
        }
    }

    /**
     * Convert the SQLite-first schema into a MySQL 8 compatible variant.
     */
    private function mysqlSchema(string $sql): string
    {
        $sql = str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'INT AUTO_INCREMENT PRIMARY KEY', $sql);
        $sql = preg_replace("/TEXT DEFAULT \\(datetime\\('now'\\)\\)/", 'DATETIME DEFAULT CURRENT_TIMESTAMP', $sql) ?? $sql;
        $sql = str_replace('TEXT DEFAULT NULL', 'VARCHAR(255) DEFAULT NULL', $sql);
        $sql = str_replace("TEXT DEFAULT ''", "VARCHAR(255) DEFAULT ''", $sql);
        $sql = str_replace("TEXT NOT NULL DEFAULT ''", "VARCHAR(255) NOT NULL DEFAULT ''", $sql);
        $sql = str_replace('TEXT NOT NULL UNIQUE', 'VARCHAR(190) NOT NULL UNIQUE', $sql);
        $sql = str_replace('tag  TEXT NOT NULL UNIQUE', 'tag  VARCHAR(190) NOT NULL UNIQUE', $sql);
        $sql = str_replace('role         TEXT DEFAULT', 'role         VARCHAR(20) DEFAULT', $sql);
        $sql = str_replace('follow_privacy TEXT DEFAULT', 'follow_privacy VARCHAR(20) DEFAULT', $sql);
        $sql = str_replace('post_visibility TEXT DEFAULT', 'post_visibility VARCHAR(20) DEFAULT', $sql);
        $sql = str_replace('dm_privacy TEXT DEFAULT', 'dm_privacy VARCHAR(20) DEFAULT', $sql);
        $sql = str_replace('theme TEXT DEFAULT', 'theme VARCHAR(32) DEFAULT', $sql);
        $sql = str_replace('status       TEXT DEFAULT', 'status       VARCHAR(20) DEFAULT', $sql);
        $sql = str_replace('status          TEXT DEFAULT', 'status          VARCHAR(20) DEFAULT', $sql);
        $sql = str_replace('status TEXT DEFAULT', 'status VARCHAR(20) DEFAULT', $sql);
        $sql = str_replace('vote     TEXT NOT NULL', 'vote     VARCHAR(20) NOT NULL', $sql);
        $sql = str_replace('type         TEXT NOT NULL', 'type         VARCHAR(40) NOT NULL', $sql);
        $sql = str_replace('target_type TEXT', 'target_type VARCHAR(40)', $sql);
        $sql = str_replace('action     TEXT NOT NULL', 'action     VARCHAR(120) NOT NULL', $sql);
        return $sql;
    }

    /**
     * Apply lightweight additive migrations for existing installations.
     */
    private function migrateSchema(): void
    {
        if (!$this->columnExists('users', 'is_verified')) {
            $this->pdo->exec('ALTER TABLE users ADD COLUMN is_verified INTEGER DEFAULT 0');
            $this->pdo->exec("UPDATE users SET is_verified = 1 WHERE verified_type IN ('business', 'government')");
        }
        if (!$this->columnExists('users', 'is_system')) {
            $this->pdo->exec('ALTER TABLE users ADD COLUMN is_system INTEGER DEFAULT 0');
        }
        if (!$this->columnExists('users', 'suspension_reason')) {
            $this->pdo->exec("ALTER TABLE users ADD COLUMN suspension_reason TEXT DEFAULT ''");
        }
        foreach ([
            'moderation_reason' => "TEXT DEFAULT ''",
            'moderation_reviewed_at' => 'TEXT DEFAULT NULL',
            'is_deleted' => 'INTEGER DEFAULT 0',
            'theme' => "VARCHAR(32) DEFAULT 'classic'",
            'auto_verified_by_affiliation' => 'INTEGER DEFAULT 0',
        ] as $column => $definition) {
            if (!$this->columnExists('users', $column)) {
                $this->pdo->exec('ALTER TABLE users ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
        foreach ([
            'is_private' => 'INTEGER DEFAULT 0',
            'follow_privacy' => "VARCHAR(20) DEFAULT 'everyone'",
            'post_visibility' => "VARCHAR(20) DEFAULT 'public'",
            'dm_privacy' => "VARCHAR(20) DEFAULT 'mutuals'",
            'totp_secret' => 'VARCHAR(64) DEFAULT NULL',
            'totp_enabled' => 'INTEGER DEFAULT 0',
        ] as $column => $definition) {
            if (!$this->columnExists('users', $column)) {
                $this->pdo->exec('ALTER TABLE users ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
        foreach ([
            'scheduled_at' => 'TEXT DEFAULT NULL',
            'location_label' => 'TEXT DEFAULT NULL',
            'location_lat' => 'REAL DEFAULT NULL',
            'location_lng' => 'REAL DEFAULT NULL',
            'gif_url' => 'TEXT DEFAULT NULL',
        ] as $column => $definition) {
            if (!$this->columnExists('tweets', $column)) {
                $this->pdo->exec('ALTER TABLE tweets ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
        $this->ensureFeatureTables();
    }

    /**
     * Ensure feature tables added after first release exist.
     */
    private function ensureFeatureTables(): void
    {
        $statements = [
            'CREATE TABLE IF NOT EXISTS tweet_media (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tweet_id INTEGER NOT NULL REFERENCES tweets(id) ON DELETE CASCADE,
                file_name TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                created_at TEXT DEFAULT (datetime(\'now\'))
            )',
            'CREATE TABLE IF NOT EXISTS polls (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tweet_id INTEGER NOT NULL UNIQUE REFERENCES tweets(id) ON DELETE CASCADE,
                question TEXT NOT NULL,
                closes_at TEXT DEFAULT NULL,
                created_at TEXT DEFAULT (datetime(\'now\'))
            )',
            'CREATE TABLE IF NOT EXISTS poll_options (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                poll_id INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
                body TEXT NOT NULL,
                position INTEGER NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS poll_votes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                poll_id INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
                option_id INTEGER NOT NULL REFERENCES poll_options(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                created_at TEXT DEFAULT (datetime(\'now\')),
                UNIQUE(poll_id, user_id)
            )',
            'CREATE TABLE IF NOT EXISTS follow_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                requester_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                target_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                status TEXT DEFAULT \'pending\',
                created_at TEXT DEFAULT (datetime(\'now\')),
                updated_at TEXT DEFAULT (datetime(\'now\')),
                UNIQUE(requester_id, target_id)
            )',
            'CREATE TABLE IF NOT EXISTS site_alerts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                message TEXT NOT NULL DEFAULT \'\',
                is_active INTEGER DEFAULT 0,
                updated_by INTEGER DEFAULT NULL REFERENCES users(id),
                created_at TEXT DEFAULT (datetime(\'now\')),
                updated_at TEXT DEFAULT (datetime(\'now\'))
            )',
            'CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(190) PRIMARY KEY,
                setting_value TEXT NOT NULL DEFAULT \'\',
                updated_by INTEGER DEFAULT NULL REFERENCES users(id),
                updated_at TEXT DEFAULT (datetime(\'now\'))
            )',
            'CREATE TABLE IF NOT EXISTS two_factor_passkeys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                credential_id TEXT NOT NULL UNIQUE,
                public_key TEXT NOT NULL,
                alg INTEGER NOT NULL,
                sign_count INTEGER DEFAULT 0,
                name TEXT DEFAULT \'Passkey\',
                created_at TEXT DEFAULT (datetime(\'now\')),
                last_used_at TEXT DEFAULT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_tweets_scheduled_at ON tweets(scheduled_at)',
            'CREATE INDEX IF NOT EXISTS idx_tweet_media_tweet_id ON tweet_media(tweet_id)',
            'CREATE INDEX IF NOT EXISTS idx_poll_options_poll_id ON poll_options(poll_id)',
            'CREATE INDEX IF NOT EXISTS idx_poll_votes_poll_id ON poll_votes(poll_id)',
            'CREATE INDEX IF NOT EXISTS idx_follow_requests_target_status ON follow_requests(target_id, status)',
            'CREATE INDEX IF NOT EXISTS idx_follow_requests_requester_status ON follow_requests(requester_id, status)',
            'CREATE INDEX IF NOT EXISTS idx_direct_messages_recipient_read ON direct_messages(recipient_id, is_read)',
            'CREATE INDEX IF NOT EXISTS idx_site_alerts_active_updated ON site_alerts(is_active, updated_at)',
            'CREATE INDEX IF NOT EXISTS idx_passkeys_user_id ON two_factor_passkeys(user_id)',
        ];

        foreach ($statements as $statement) {
            $this->pdo->exec($this->isMysql() ? $this->mysqlSchema($statement) : $statement);
        }
    }

    /**
     * Return true if a table column exists.
     */
    private function columnExists(string $table, string $column): bool
    {
        if ($this->isMysql()) {
            $row = $this->one(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
                ['table' => $table, 'column' => $column]
            );
            return $row !== null;
        }

        foreach ($this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $row) {
            if (($row['name'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ensure the non-login CommunityNotes system account exists.
     */
    private function ensureCommunityNotesBot(): void
    {
        $existing = $this->one('SELECT id FROM users WHERE username = :username', ['username' => 'CommunityNotes']);
        if ($existing) {
            $this->execute(
                'UPDATE users SET display_name = :display_name, is_system = 1, is_suspended = 0, is_verified = 1, bio = :bio WHERE id = :id',
                [
                    'display_name' => 'Community Notes',
                    'bio' => 'System account used for administrator-created Community Notes.',
                    'id' => (int)$existing['id'],
                ]
            );
            return;
        }

        $this->execute(
            'INSERT INTO users (username, display_name, email, password, bio, is_verified, is_system)
             VALUES (:username, :display_name, :email, :password, :bio, 1, 1)',
            [
                'username' => 'CommunityNotes',
                'display_name' => 'Community Notes',
                'email' => 'communitynotes@twitkey.local',
                'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
                'bio' => 'System account used for administrator-created Community Notes.',
            ]
        );
    }

    /**
     * Force the configured owner account into a non-destructive owner state.
     */
    private function ensureOwnerAccountState(): void
    {
        foreach ([[1, 'm5rcel'], [2, 'twitkey']] as [$id, $username]) {
            $owner = $this->one('SELECT id, username FROM users WHERE id = :id LIMIT 1', ['id' => $id]);
            if (!$owner || strtolower((string)$owner['username']) !== $username) {
                continue;
            }
            $this->execute(
                "UPDATE users
                 SET is_admin = 1,
                     role = 'admin',
                     is_verified = 1,
                     is_suspended = 0,
                     is_deleted = 0,
                     suspension_reason = '',
                     moderation_reason = '',
                     moderation_reviewed_at = NULL,
                     updated_at = :updated_at
                 WHERE id = :id AND lower(username) = :username",
                ['updated_at' => date('Y-m-d H:i:s'), 'id' => $id, 'username' => $username]
            );
        }
    }

    /**
     * Split SQL statements while respecting simple string literals.
     *
     * @return array<int, string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $inString = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($char === "'" && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $inString = !$inString;
            }
            if ($char === ';' && !$inString) {
                $statements[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }
        return $statements;
    }
}
