import { z } from 'zod'
import { ACCOUNT_TYPES, FREQUENCIES, TRANSACTION_TYPES } from '../types'

/**
 * Form schemas.
 *
 * Every field is modelled as a string, because that is what a DOM control
 * actually produces — a `<select>` with no selection yields `''`, and a number
 * input yields `''` rather than `undefined` when cleared. Coercing inside the
 * schema would turn both into `0` and report "must be greater than zero" where
 * the real problem is "you didn't pick anything". Validating the string and
 * converting once, at the submit boundary, keeps the messages honest.
 *
 * The API's own validation still runs and is still surfaced — this layer exists
 * to catch mistakes before a round trip, not to replace the server's rules.
 */

/** Up to two decimal places, no sign — the API wants positive major units. */
const MONEY = /^\d+(\.\d{1,2})?$/
/** Same, but a leading minus is allowed for an opening balance. */
const SIGNED_MONEY = /^-?\d+(\.\d{1,2})?$/

const positiveAmount = (label: string) =>
  z
    .string()
    .min(1, `${label} is required`)
    .regex(MONEY, 'Enter an amount like 42.55')
    .refine((value) => Number(value) > 0, { message: `${label} must be greater than zero` })

const isoDate = z
  .string()
  .min(1, 'Pick a date')
  .regex(/^\d{4}-\d{2}-\d{2}$/, 'Pick a valid date')

const isoMonth = z
  .string()
  .min(1, 'Pick a month')
  .regex(/^\d{4}-\d{2}$/, 'Pick a valid month')

/* -------------------------------------------------------------------------- */
/* Auth                                                                       */
/* -------------------------------------------------------------------------- */

export const loginSchema = z.object({
  email: z.string().min(1, 'Email is required').email('Enter a valid email address'),
  password: z.string().min(1, 'Password is required'),
})
export type LoginFormValues = z.infer<typeof loginSchema>

export const registerSchema = z.object({
  name: z.string().trim().min(1, 'Name is required').max(80, 'Name must be 80 characters or fewer'),
  email: z.string().min(1, 'Email is required').email('Enter a valid email address'),
  // Mirrors the API's own minimum, so the failure is caught before the request.
  password: z.string().min(8, 'Use at least 8 characters'),
})
export type RegisterFormValues = z.infer<typeof registerSchema>

/* -------------------------------------------------------------------------- */
/* Transactions                                                               */
/* -------------------------------------------------------------------------- */

export const transactionSchema = z
  .object({
    type: z.enum(TRANSACTION_TYPES),
    account_id: z.string().min(1, 'Choose an account'),
    category_id: z.string(),
    transfer_to_account_id: z.string(),
    amount: positiveAmount('Amount'),
    description: z
      .string()
      .trim()
      .min(1, 'Add a short description')
      .max(255, 'Keep the description under 255 characters'),
    notes: z.string().max(2000, 'Notes must be 2000 characters or fewer'),
    /** Comma-separated in the field, split into an array on submit. */
    tags: z.string(),
    transaction_date: isoDate,
  })
  .superRefine((values, ctx) => {
    // The category/destination requirement flips with the type, which is
    // exactly the kind of rule a flat field-by-field schema cannot express.
    if (values.type === 'transfer') {
      if (!values.transfer_to_account_id) {
        ctx.addIssue({ code: 'custom', path: ['transfer_to_account_id'], message: 'Choose the destination account' })
      } else if (values.transfer_to_account_id === values.account_id) {
        ctx.addIssue({ code: 'custom', path: ['transfer_to_account_id'], message: 'Pick a different account' })
      }
    } else if (!values.category_id) {
      ctx.addIssue({ code: 'custom', path: ['category_id'], message: 'Choose a category' })
    }
  })

export type TransactionFormValues = z.infer<typeof transactionSchema>

/* -------------------------------------------------------------------------- */
/* Budgets                                                                    */
/* -------------------------------------------------------------------------- */

export const budgetSchema = z.object({
  category_id: z.string().min(1, 'Choose a category'),
  month: isoMonth,
  amount_limit: positiveAmount('Limit'),
})
export type BudgetFormValues = z.infer<typeof budgetSchema>

/* -------------------------------------------------------------------------- */
/* Accounts                                                                   */
/* -------------------------------------------------------------------------- */

export const accountSchema = z.object({
  name: z.string().trim().min(1, 'Name is required').max(80, 'Name must be 80 characters or fewer'),
  type: z.enum(ACCOUNT_TYPES),
  initial_balance: z
    .string()
    .min(1, 'Opening balance is required')
    .regex(SIGNED_MONEY, 'Enter an amount like 1250.75'),
  currency: z
    .string()
    .trim()
    .min(3, 'Use a three-letter code')
    .max(3, 'Use a three-letter code')
    .regex(/^[A-Za-z]{3}$/, 'Use a three-letter code like USD'),
})
export type AccountFormValues = z.infer<typeof accountSchema>

/* -------------------------------------------------------------------------- */
/* Recurring transactions                                                     */
/* -------------------------------------------------------------------------- */

export const recurringSchema = z.object({
  // Transfers cannot be scheduled — the API only accepts income or expense.
  type: z.enum(['income', 'expense']),
  account_id: z.string().min(1, 'Choose an account'),
  category_id: z.string().min(1, 'Choose a category'),
  amount: positiveAmount('Amount'),
  description: z.string().trim().min(1, 'Add a short description').max(255, 'Keep it under 255 characters'),
  frequency: z.enum(FREQUENCIES),
  next_run_date: isoDate,
  active: z.boolean(),
})
export type RecurringFormValues = z.infer<typeof recurringSchema>

/* -------------------------------------------------------------------------- */

/** Splits the tags field into the array the API expects. */
export function parseTags(input: string): string[] {
  return input
    .split(',')
    .map((tag) => tag.trim())
    .filter((tag) => tag.length > 0)
}
