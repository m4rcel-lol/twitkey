-- USERS
CREATE TABLE IF NOT EXISTS users (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    username     TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    email        TEXT NOT NULL UNIQUE,
    password     TEXT NOT NULL,
    bio          TEXT DEFAULT '',
    location     TEXT DEFAULT '',
    website      TEXT DEFAULT '',
    avatar       TEXT DEFAULT NULL,
    background   TEXT DEFAULT NULL,
    role         TEXT DEFAULT 'user' CHECK(role IN ('user','admin')),
    verified_type TEXT DEFAULT NULL CHECK(verified_type IN (NULL,'business','government')),
    is_verified INTEGER DEFAULT 0,
    is_admin     INTEGER DEFAULT 0,
    is_system    INTEGER DEFAULT 0,
    is_suspended INTEGER DEFAULT 0,
    suspension_reason TEXT DEFAULT '',
    moderation_reason TEXT DEFAULT '',
    moderation_reviewed_at TEXT DEFAULT NULL,
    is_deleted   INTEGER DEFAULT 0,
    is_private   INTEGER DEFAULT 0,
    follow_privacy TEXT DEFAULT 'everyone' CHECK(follow_privacy IN ('everyone','approve')),
    post_visibility TEXT DEFAULT 'public' CHECK(post_visibility IN ('public','followers')),
    dm_privacy TEXT DEFAULT 'mutuals' CHECK(dm_privacy IN ('everyone','mutuals','none')),
    theme TEXT DEFAULT 'classic' CHECK(theme IN ('classic','night','forest','ruby','high_contrast','ocean','lavender','gold','bubblegum','grayscale','midnight','terminal','custom')),
    theme_choice VARCHAR(32) DEFAULT 'classic',
    custom_theme_css TEXT,
    totp_secret TEXT DEFAULT NULL,
    totp_enabled INTEGER DEFAULT 0,
    auto_verified_by_affiliation INTEGER DEFAULT 0,
    follower_count   INTEGER DEFAULT 0,
    following_count  INTEGER DEFAULT 0,
    tweet_count      INTEGER DEFAULT 0,
    created_at   TEXT DEFAULT (datetime('now')),
    updated_at   TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS two_factor_passkeys (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    credential_id TEXT NOT NULL UNIQUE,
    public_key    TEXT NOT NULL,
    alg           INTEGER NOT NULL,
    sign_count    INTEGER DEFAULT 0,
    name          TEXT DEFAULT 'Passkey',
    created_at    TEXT DEFAULT (datetime('now')),
    last_used_at  TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS remember_tokens (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    selector     TEXT NOT NULL UNIQUE,
    token_hash   TEXT NOT NULL,
    expires_at   VARCHAR(32) NOT NULL,
    created_at   TEXT DEFAULT (datetime('now')),
    last_used_at TEXT DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_remember_tokens_user_id ON remember_tokens(user_id);
CREATE INDEX IF NOT EXISTS idx_remember_tokens_expires_at ON remember_tokens(expires_at);

-- TWEETS
CREATE TABLE IF NOT EXISTS tweets (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body         TEXT NOT NULL,
    reply_to_id  INTEGER DEFAULT NULL REFERENCES tweets(id) ON DELETE SET NULL,
    retweet_of_id INTEGER DEFAULT NULL REFERENCES tweets(id) ON DELETE SET NULL,
    favorite_count  INTEGER DEFAULT 0,
    retweet_count   INTEGER DEFAULT 0,
    reply_count     INTEGER DEFAULT 0,
    is_deleted   INTEGER DEFAULT 0,
    scheduled_at TEXT DEFAULT NULL,
    location_label TEXT DEFAULT NULL,
    location_lat REAL DEFAULT NULL,
    location_lng REAL DEFAULT NULL,
    gif_url TEXT DEFAULT NULL,
    created_at   TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS tweet_media (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tweet_id   INTEGER NOT NULL REFERENCES tweets(id) ON DELETE CASCADE,
    file_name  TEXT NOT NULL,
    mime_type  TEXT NOT NULL,
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS polls (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tweet_id   INTEGER NOT NULL UNIQUE REFERENCES tweets(id) ON DELETE CASCADE,
    question   TEXT NOT NULL,
    closes_at  TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS poll_options (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id  INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
    body     TEXT NOT NULL,
    position INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS poll_votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id    INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
    option_id  INTEGER NOT NULL REFERENCES poll_options(id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TEXT DEFAULT (datetime('now')),
    UNIQUE(poll_id, user_id)
);

-- FOLLOWS
CREATE TABLE IF NOT EXISTS follows (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    follower_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    following_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at   TEXT DEFAULT (datetime('now')),
    UNIQUE(follower_id, following_id)
);

CREATE TABLE IF NOT EXISTS follow_requests (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    requester_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    target_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    status       TEXT DEFAULT 'pending' CHECK(status IN ('pending','approved','declined')),
    created_at   TEXT DEFAULT (datetime('now')),
    updated_at   TEXT DEFAULT (datetime('now')),
    UNIQUE(requester_id, target_id)
);

-- FAVORITES
CREATE TABLE IF NOT EXISTS favorites (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    tweet_id   INTEGER NOT NULL REFERENCES tweets(id) ON DELETE CASCADE,
    created_at TEXT DEFAULT (datetime('now')),
    UNIQUE(user_id, tweet_id)
);

-- RETWEETS
CREATE TABLE IF NOT EXISTS retweets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    tweet_id   INTEGER NOT NULL REFERENCES tweets(id) ON DELETE CASCADE,
    created_at TEXT DEFAULT (datetime('now')),
    UNIQUE(user_id, tweet_id)
);

-- NOTIFICATIONS
CREATE TABLE IF NOT EXISTS notifications (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    actor_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type         TEXT NOT NULL CHECK(type IN ('follow','favorite','retweet','reply','mention','affiliation_invite','note_flag')),
    tweet_id     INTEGER DEFAULT NULL REFERENCES tweets(id) ON DELETE CASCADE,
    is_read      INTEGER DEFAULT 0,
    created_at   TEXT DEFAULT (datetime('now'))
);

-- COMMUNITY NOTES
CREATE TABLE IF NOT EXISTS community_notes (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    tweet_id     INTEGER NOT NULL REFERENCES tweets(id) ON DELETE CASCADE,
    author_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body         TEXT NOT NULL,
    status       TEXT DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
    helpful_votes   INTEGER DEFAULT 0,
    unhelpful_votes INTEGER DEFAULT 0,
    reviewed_by  INTEGER DEFAULT NULL REFERENCES users(id),
    reviewed_at  TEXT DEFAULT NULL,
    created_at   TEXT DEFAULT (datetime('now'))
);

-- COMMUNITY NOTE VOTES
CREATE TABLE IF NOT EXISTS community_note_votes (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    note_id  INTEGER NOT NULL REFERENCES community_notes(id) ON DELETE CASCADE,
    user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    vote     TEXT NOT NULL CHECK(vote IN ('helpful','unhelpful')),
    UNIQUE(note_id, user_id)
);

CREATE TABLE IF NOT EXISTS community_note_flags (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    note_id  INTEGER NOT NULL REFERENCES community_notes(id) ON DELETE CASCADE,
    user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    reason   TEXT DEFAULT '',
    created_at TEXT DEFAULT (datetime('now')),
    UNIQUE(note_id, user_id)
);

-- AFFILIATIONS
CREATE TABLE IF NOT EXISTS affiliations (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    business_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    affiliated_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    status          TEXT DEFAULT 'pending' CHECK(status IN ('pending','accepted','revoked')),
    created_at      TEXT DEFAULT (datetime('now')),
    UNIQUE(business_id, affiliated_id)
);

-- DIRECT MESSAGES
CREATE TABLE IF NOT EXISTS direct_messages (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sender_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    recipient_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body         TEXT NOT NULL,
    is_read      INTEGER DEFAULT 0,
    created_at   TEXT DEFAULT (datetime('now'))
);

-- HASHTAGS
CREATE TABLE IF NOT EXISTS hashtags (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    tag  TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS tweet_hashtags (
    tweet_id   INTEGER NOT NULL REFERENCES tweets(id) ON DELETE CASCADE,
    hashtag_id INTEGER NOT NULL REFERENCES hashtags(id) ON DELETE CASCADE,
    PRIMARY KEY(tweet_id, hashtag_id)
);

-- ADMIN LOG
CREATE TABLE IF NOT EXISTS admin_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id   INTEGER NOT NULL REFERENCES users(id),
    action     TEXT NOT NULL,
    target_type TEXT,
    target_id  INTEGER,
    note       TEXT,
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS site_alerts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    message    TEXT NOT NULL DEFAULT '',
    is_active  INTEGER DEFAULT 0,
    updated_by INTEGER DEFAULT NULL REFERENCES users(id),
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key   VARCHAR(190) PRIMARY KEY,
    setting_value TEXT NOT NULL DEFAULT '',
    updated_by    INTEGER DEFAULT NULL REFERENCES users(id),
    updated_at    TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_tweets_user_id ON tweets(user_id);
CREATE INDEX IF NOT EXISTS idx_tweets_created_at ON tweets(created_at);
CREATE INDEX IF NOT EXISTS idx_tweets_scheduled_at ON tweets(scheduled_at);
CREATE INDEX IF NOT EXISTS idx_tweets_reply_to_id ON tweets(reply_to_id);
CREATE INDEX IF NOT EXISTS idx_follows_follower_id ON follows(follower_id);
CREATE INDEX IF NOT EXISTS idx_follows_following_id ON follows(following_id);
CREATE INDEX IF NOT EXISTS idx_follow_requests_target_status ON follow_requests(target_id, status);
CREATE INDEX IF NOT EXISTS idx_follow_requests_requester_status ON follow_requests(requester_id, status);
CREATE INDEX IF NOT EXISTS idx_tweet_hashtags_hashtag_id ON tweet_hashtags(hashtag_id);
CREATE INDEX IF NOT EXISTS idx_notifications_user_id ON notifications(user_id);
CREATE INDEX IF NOT EXISTS idx_notifications_is_read ON notifications(is_read);
CREATE INDEX IF NOT EXISTS idx_favorites_user_id ON favorites(user_id);
CREATE INDEX IF NOT EXISTS idx_direct_messages_pair ON direct_messages(sender_id, recipient_id);
CREATE INDEX IF NOT EXISTS idx_direct_messages_recipient_read ON direct_messages(recipient_id, is_read);
CREATE INDEX IF NOT EXISTS idx_community_notes_status ON community_notes(status);
CREATE INDEX IF NOT EXISTS idx_tweet_media_tweet_id ON tweet_media(tweet_id);
CREATE INDEX IF NOT EXISTS idx_poll_options_poll_id ON poll_options(poll_id);
CREATE INDEX IF NOT EXISTS idx_poll_votes_poll_id ON poll_votes(poll_id);
CREATE INDEX IF NOT EXISTS idx_site_alerts_active_updated ON site_alerts(is_active, updated_at);
