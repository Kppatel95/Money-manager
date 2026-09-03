-- Free-text field populated by the bill-scan feature (and available to any
-- manually entered transaction too): how the bill says it was paid (e.g.
-- "Cash", "Credit Card", "UPI"), independent of which of the user's own
-- accounts the transaction is filed against. Not a foreign key -- unlike
-- subcategory (see migration 0011), there is no fixed list to match against.
ALTER TABLE transactions ADD COLUMN payment_method TEXT;
