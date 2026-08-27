-- Categories are either system defaults (user_id IS NULL, visible to every
-- user, read-only) or user-owned rows the user may rename and delete.
CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    name TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('income', 'expense')),
    icon TEXT NOT NULL DEFAULT '',
    color TEXT NOT NULL DEFAULT '#888888',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_categories_user_id ON categories(user_id);

INSERT INTO categories (user_id, name, type, icon, color) VALUES
    (NULL, 'Salary',        'income',  '💰', '#2e7d32'),
    (NULL, 'Freelance',     'income',  '🧑‍💻', '#388e3c'),
    (NULL, 'Investments',   'income',  '📈', '#00796b'),
    (NULL, 'Food',          'expense', '🍽️', '#e64a19'),
    (NULL, 'Transport',     'expense', '🚌', '#1976d2'),
    (NULL, 'Shopping',      'expense', '🛍️', '#7b1fa2'),
    (NULL, 'Bills',         'expense', '🧾', '#455a64'),
    (NULL, 'Entertainment', 'expense', '🎬', '#c2185b'),
    (NULL, 'Health',        'expense', '🏥', '#0288d1'),
    (NULL, 'Other',         'expense', '🗂️', '#616161');
