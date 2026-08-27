-- Retires the v1 single-table expense log.
--
-- Existing rows are not thrown away: each user's expenses are moved into the
-- new ledger under an account called 'Imported', matched to a system category
-- by name where one exists and filed under 'Other' where it does not. Amounts
-- become integer cents. Only then is the old table dropped.
--
-- On a fresh database the expenses table is empty and these statements are
-- no-ops.

INSERT OR IGNORE INTO accounts (user_id, name, type, initial_balance, currency)
SELECT DISTINCT e.user_id, 'Imported', 'cash', 0, 'USD'
FROM expenses e
WHERE e.amount > 0;

INSERT INTO transactions
    (user_id, account_id, category_id, type, amount, description, notes, tags, transaction_date, created_at, updated_at)
SELECT
    e.user_id,
    (SELECT a.id FROM accounts a WHERE a.user_id = e.user_id AND a.name = 'Imported'),
    COALESCE(
        (SELECT c.id FROM categories c
          WHERE c.user_id IS NULL AND c.type = 'expense'
            AND lower(c.name) = lower(e.category)),
        (SELECT c.id FROM categories c WHERE c.user_id IS NULL AND c.name = 'Other')
    ),
    'expense',
    CAST(ROUND(e.amount * 100) AS INTEGER),
    COALESCE(e.description, ''),
    'Imported from the v1 expense log. Original category: ' || e.category,
    '["imported"]',
    e.expense_date,
    e.created_at,
    e.updated_at
FROM expenses e
WHERE e.amount > 0;

DROP INDEX IF EXISTS idx_expenses_user_id;
DROP INDEX IF EXISTS idx_expenses_category;
DROP INDEX IF EXISTS idx_expenses_date;
DROP TABLE IF EXISTS expenses;
