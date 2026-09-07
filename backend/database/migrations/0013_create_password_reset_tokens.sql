-- Opaque password reset tokens. Only the SHA-256 hash is stored, same
-- reasoning as refresh_tokens: a database leak hands out nothing usable.
-- Single-use by deletion rather than a "used" flag -- a reset token is spent
-- once and thrown away, there is no reason to keep a dead row around.
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_user ON password_reset_tokens(user_id);
