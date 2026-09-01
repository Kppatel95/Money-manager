-- Subcategories break a category down further (e.g. Food -> Groceries,
-- Family Dinner). Unlike categories there is no per-user ownership yet: every
-- row here is a system default seeded below and shared by every user, the
-- same read-only shape categories have before a user creates their own.
CREATE TABLE IF NOT EXISTS subcategories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_subcategories_category_id ON subcategories(category_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_subcategories_category_name ON subcategories(category_id, name COLLATE NOCASE);

ALTER TABLE transactions ADD COLUMN subcategory_id INTEGER REFERENCES subcategories(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_transactions_subcategory ON transactions(subcategory_id);

INSERT INTO subcategories (category_id, name) VALUES
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Food'), 'Groceries'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Food'), 'Dining Out'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Food'), 'Family Dinner'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Food'), 'Coffee & Snacks'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Food'), 'Delivery & Takeout'),

    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Transport'), 'Fuel'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Transport'), 'Public Transit'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Transport'), 'Rideshare & Taxi'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Transport'), 'Parking & Tolls'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Transport'), 'Maintenance & Repairs'),

    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Shopping'), 'Clothing & Accessories'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Shopping'), 'Electronics'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Shopping'), 'Home & Furniture'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Shopping'), 'Personal Care'),

    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Bills'), 'Rent & Mortgage'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Bills'), 'Utilities'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Bills'), 'Internet & Phone'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Bills'), 'Insurance'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Bills'), 'Subscriptions'),

    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Entertainment'), 'Movies & Shows'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Entertainment'), 'Games'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Entertainment'), 'Events & Concerts'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Entertainment'), 'Hobbies'),

    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Health'), 'Doctor & Dental'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Health'), 'Pharmacy'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Health'), 'Fitness'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Health'), 'Health Insurance'),

    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Other'), 'Gifts & Donations'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Other'), 'Fees & Charges'),
    ((SELECT id FROM categories WHERE user_id IS NULL AND type = 'expense' AND name = 'Other'), 'Miscellaneous');
