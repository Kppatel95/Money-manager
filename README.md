# Personal Finance Manager

A full-stack personal finance app: multiple accounts, a categorised
transaction ledger with transfers, monthly budgets, recurring entries, and a
dashboard that reports net worth, spending breakdowns and a six-month trend.
Built as a portfolio piece around a hand-rolled, layered PHP API paired with a
React/TypeScript frontend.

## Stack

- **Backend:** PHP 8, no framework. A layered API (controllers -> services ->
  repositories) behind a small front-controller router, JWT access tokens with
  rotating refresh tokens, and SQLite via PDO so there is no database server to
  stand up. Schema changes are numbered migration files.
- **Frontend:** React 19 + TypeScript on Vite. Client-side routing, a typed
  fetch layer that refreshes expired access tokens transparently, Recharts for
  the dashboard charts, React Hook Form + Zod for the forms, and hand-written
  CSS driven by custom properties for the light/dark themes.
- **Tests:** PHPUnit -- unit tests for the pure logic (money parsing,
  validation, recurrence dates, error mapping) and integration tests that run
  the real router and real SQL against a throwaway database per test.

Why no PHP framework? Hand-rolling the routing, request/response and error
layers is a more direct way to show how the pieces fit together than
`php artisan make:controller` would be. The tradeoff is obvious -- a real
production API would reach for Laravel or Symfony and get auth, validation and
migrations for free -- but at this size the plain version stays readable end to
end, and the layering below is exactly what a framework would have imposed
anyway.

## Features

- Registration and login; a 15-minute JWT access token plus a 7-day refresh
  token that rotates on every use, and login rate limiting.
- **Accounts** of five kinds (cash, bank, card, wallet, savings), each with an
  opening balance, a currency label, and a balance derived from its
  transactions. Deleting an account that has history archives it instead.
- **Categories**: a shared set of system defaults every user sees, plus your
  own, with an icon and colour.
- **Transactions**: income, expense and transfers between your own accounts,
  with description, notes and tags. Filter by account, category, type, date
  range or free-text search; paginated; exportable as CSV.
- **Budgets**: a monthly limit per category, returned with spend and remainder
  computed from the ledger.
- **Recurring transactions**: daily, weekly or monthly schedules that
  materialise into real transactions when they come due, catching up anything
  missed.
- **Dashboard**: net worth, the month's income/expense/net and savings rate, a
  ranked category breakdown, a six-month trend and budget progress -- in one
  request.
- Consistent JSON error envelope with correct status codes (400, 401, 403,
  404, 405, 409, 422, 429).

## Project layout

```
backend/    PHP API
  bin/                  CLI entry points (migrate, run-recurring)
  database/migrations/  numbered .sql migrations
  public/index.php      the only web-reachable file
  src/
    Controllers/        HTTP in, HTTP out
    Services/           business rules
    Repositories/       the only place SQL lives
    Exceptions/         domain errors that map to status codes
    Http/               composition root and error handler
    Support/            request, response, logger, money, env, CSV
    Validation/         input validation
  tests/                PHPUnit unit + integration suites
  openapi.yaml          API specification
frontend/   React + TypeScript app (Vite)
  src/
    api/                typed client, token store, one function per endpoint
    components/         layout shell, charts, reusable UI primitives
    context/            auth, theme, toasts, shared reference data
    forms/              account / transaction / budget / recurring forms
    hooks/              async loading, debounce
    lib/                money, dates, Zod schemas, form helpers
    pages/              one file per route
    styles/             tokens -> base -> layout -> components -> pages
    types.ts            every API request/response shape
```

## Architecture

The API is layered, and each layer has exactly one job:

- **Controllers** deal with HTTP and nothing else: read the request, call a
  service, wrap the result in a response. They contain no business rules and no
  SQL, and they never set a status code for an error -- they throw.
- **Services** own the business rules: what a valid transaction looks like,
  when an account may be deleted rather than archived, how a budget's spend is
  computed, when a recurring schedule is due. They are the layer worth testing
  hardest, and they know nothing about HTTP.
- **Repositories** are the only classes that touch PDO. Every method takes a
  user id and every query filters on it, so scoping is not something a caller
  can forget.
- **Domain exceptions** (`ValidationException`, `NotFoundException`,
  `UnauthorizedException`, `ForbiddenException`, `ConflictException`,
  `RateLimitedException`) are thrown anywhere and translated to status codes in
  one place, `Http\ErrorHandler`. Every error response has the same shape:

  ```json
  {"error": {"code": "VALIDATION_ERROR", "message": "The given data was invalid.",
             "details": {"amount": "Amount must be greater than zero."}}}
  ```

- **`Http\Application`** is the composition root: it builds the object graph by
  hand, registers the routes and turns a `Request` into a `Response`. Because
  handlers *return* responses instead of echoing and exiting, the integration
  tests dispatch through the real router in-process -- no web server, no output
  buffering.

A note on 403 versus 404: touching something that belongs to another user
always returns **404**. A 403 would confirm that the id exists, which is a small
information leak the API declines to make. 403 is reserved for resources you can
legitimately see but may not change, like the shared system categories.

## Running the backend

Requires PHP 8.1+ and Composer.

```bash
cd backend
composer install
cp .env.example .env    # optional -- sane defaults are used if you skip this
php bin/migrate.php     # create/upgrade the database
php -S localhost:8000 -t public
```

The API is now at `http://localhost:8000`, with everything under `/api/v1`.

Migrations also run automatically on boot, so a fresh clone works without the
migrate step. `php bin/migrate.php` is the production path: run it during
deploy, before the new code starts serving traffic, and use
`php bin/migrate.php --status` to see what has and has not been applied.

To run the test suite:

```bash
cd backend
vendor/bin/phpunit
```

### With Docker instead

```bash
docker compose up --build
```

Same API on `http://localhost:8000`. The container migrates on start and keeps
the SQLite file and logs on a named volume, so `docker compose down` preserves
your data (`down -v` does not). Set `JWT_SECRET` in the environment before
using it for anything real.

### API summary

Full request/response schemas are in [`backend/openapi.yaml`](backend/openapi.yaml).

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| POST | `/api/v1/auth/register` | no | `name`, `email`, `password` (8+ chars) |
| POST | `/api/v1/auth/login` | no | returns an access + refresh token pair |
| POST | `/api/v1/auth/refresh` | no | `refresh_token`; rotates it |
| POST | `/api/v1/auth/logout` | yes | revokes the refresh token |
| GET | `/api/v1/auth/me` | yes | the signed-in user |
| GET | `/api/v1/accounts` | yes | `?include_archived=true` to see archived ones |
| POST | `/api/v1/accounts` | yes | `name`, `type`, optional `initial_balance`, `currency` |
| GET/PUT/DELETE | `/api/v1/accounts/{id}` | yes | DELETE archives if the account has history |
| GET | `/api/v1/accounts/{id}/balance` | yes | balance plus the movements behind it |
| GET/POST | `/api/v1/categories` | yes | `?type=income\|expense` |
| PUT/DELETE | `/api/v1/categories/{id}` | yes | user-owned only; system defaults are 403 |
| GET | `/api/v1/transactions` | yes | `account_id`, `category_id`, `type`, `date_from`, `date_to`, `search`, `page`, `per_page` |
| POST | `/api/v1/transactions` | yes | income, expense or transfer |
| GET/PUT/DELETE | `/api/v1/transactions/{id}` | yes | PUT accepts a partial payload |
| GET | `/api/v1/transactions/export` | yes | CSV of the filtered set, unpaginated |
| GET/POST | `/api/v1/budgets` | yes | `?month=YYYY-MM`; each budget carries `spent`/`remaining` |
| PUT/DELETE | `/api/v1/budgets/{id}` | yes | |
| GET/POST | `/api/v1/recurring-transactions` | yes | `daily`, `weekly` or `monthly` |
| PUT/DELETE | `/api/v1/recurring-transactions/{id}` | yes | |
| GET | `/api/v1/dashboard/summary` | yes | `?month=YYYY-MM` |

Authenticated requests send `Authorization: Bearer <access_token>`.

## Why these design choices

**Money is stored as integer cents.** `0.1 + 0.2` is not `0.3` in binary
floating point, and a ledger that sums thousands of rows will drift. Cents are
exact, `SUM()` over integers stays exact, and decimals appear only at the edges:
parsing input and formatting output. Responses carry both -- `amount_cents` is
the source of truth, `amount` is there so a UI does not have to divide.

**Balances and budget spend are derived, not stored.** An account's balance is
its opening balance plus the signed sum of its transactions; a budget's spend is
the sum of that category's expenses in that month. Both are one query. A stored
running total would be faster and would eventually be wrong -- every edit,
deletion and back-dated entry becomes an opportunity to forget to update it.
When the query stops being cheap, caching goes behind the service that owns it.

**Numbered migrations instead of one schema.sql.** A single schema file answers
"what does a new database look like" but not "how does an existing one get
there", which is the question that matters after the first deploy. Numbered
files with a ledger table give a linear, reviewable history, run exactly once,
and can carry data with them -- the migration that retired the old
single-table expense log moves those rows into the new ledger before dropping
it, rather than throwing history away.

**A hand-rolled logger instead of a dependency.** The requirement is "append a
level, a message and some context to a file". Monolog would bring a handler and
formatter hierarchy along for it. The `Logger` class is a hundred lines with the
PSR-3 method names and `{placeholder}` interpolation, so if this ever needs
syslog or structured shipping, swapping in a real PSR-3 implementation is a
constructor change -- nothing outside that class knows how the writing happens.

**Recurring transactions run on request, not on cron.** Due schedules are
caught up by the first authenticated request, which costs one indexed lookup
that usually returns nothing. The payoff is that recurring entries just happen
with no scheduler to install, and `runDue()` catches up every missed occurrence,
so the ledger is the same whether the app is opened daily or once a quarter.
The limitation is real: nothing happens while nobody logs in. Where a scheduler
exists, `php bin/run-recurring.php` does the same sweep for every user nightly
and the request-time hook becomes a no-op.

**Access tokens are short, refresh tokens are revocable.** A JWT cannot be
revoked, so the access token expires in 15 minutes and the long-lived half of
the session is an opaque refresh token, stored only as a SHA-256 hash and
rotated on every use. Logging out revokes the refresh token; the access token
keeps working until it expires, which is the standard tradeoff for stateless
tokens and the reason theirs is short.

## The frontend

A multi-page React app built against the API above. Seven signed-in routes plus
login and registration:

| Route | What it does |
|-------|--------------|
| `/` | Dashboard: net worth, the month's income/expense/net/savings rate, a category donut, a six-month income-vs-expense chart, budget progress and account balances |
| `/accounts` | Account cards with derived balances, opening balance and movement; add, edit, archive |
| `/transactions` | Filterable, searchable, paginated ledger; income/expense/transfer form; CSV export of the current filter set |
| `/budgets` | Per-month view with limit, spend, remainder and a progress bar per category; add, edit, delete |
| `/recurring` | Schedules with frequency, next run and a pause/resume toggle |
| `/reports` | Monthly totals, the trend chart, a ranked category table and a CSV export with its own range and filters |
| `/settings` | The signed-in user from `/auth/me`, theme preference, workspace counts, sign out |

Throughout: loading skeletons, empty states with a call to action, toast
notifications, confirmation before anything destructive, and a sidebar that
collapses into a drawer below 900px.

### Notable pieces

**The API client refreshes tokens by itself.** `src/api/client.ts` is the only
place that talks to `fetch`. On a 401 it posts the refresh token to
`/auth/refresh`, replays the original request with the new access token, and
only gives up — clearing the session and dropping the user at the login screen —
if the refresh itself fails. Concurrent 401s share a single in-flight refresh:
because the API rotates the refresh token on every use, five parallel refreshes
would spend the token once and get 401s for the other four, ending a perfectly
healthy session. `src/api/resources.ts` sits on top with one function per
endpoint, so no component builds a URL or unwraps a `data` envelope.

**Money never becomes a float.** Every display path starts from the API's
integer `*_cents` field and divides once, at formatting time; writes send
major-unit strings back. `src/lib/money.ts` is the only file that does either.

**Filters live in the URL.** The transactions page keeps its account, category,
type, date-range, search and page state in the query string, so a filtered view
is linkable and the back button behaves.

**The chart palette was validated, not chosen by eye.** Series colours are a
fixed categorical ramp (`--chart-1` … `--chart-7`) in an order where every
adjacent pair stays separable under deuteranopia, protanopia and tritanopia, in
both themes; a longer tail folds into a neutral "Other" rather than inventing a
hue. Income and expense are the first two slots rather than green and red — the
finance cliché is also the worst pair for the most common colour vision
deficiency. Every chart carries a legend and written values, so nothing is
encoded by colour alone.

**Themes are CSS custom properties.** `styles/tokens.css` holds every colour,
radius and spacing step; the dark theme redefines about twenty of them and no
other stylesheet contains a literal colour. A tiny inline script in
`index.html` applies the stored preference before React mounts, so a reload does
not flash light-on-dark.

### Dependencies, and why

- **`react-router-dom`** — the app is genuinely multi-page: deep links, guarded
  routes, and filter state in the query string. Hand-rolling that is a worse
  version of a solved problem.
- **`recharts`** — real SVG charts with axes, tooltips and legends. Composable
  React components rather than an imperative charting API, which suits a donut
  and a grouped bar chart driven by the same theme tokens.
- **`react-hook-form`** + **`zod`** (+ **`@hookform/resolvers`**) — the
  transaction form changes its own shape: a transfer needs a destination account
  and must carry no category, while income and expense need the opposite. That
  is a cross-field rule, and it belongs in a schema (`lib/schemas.ts`) rather
  than scattered through JSX. Zod also gives the form value types for free via
  `z.infer`, and uncontrolled inputs mean typing in one field does not re-render
  the whole form.

There is no CSS framework and no component library. The styling is a few hundred
lines of plain CSS organised as tokens -> base -> layout -> components -> pages.

## Running the frontend

Requires Node 18+.

```bash
cd frontend
npm install
cp .env.example .env    # set VITE_API_URL if the backend isn't on :8000
npm run dev
```

The dev server comes up on `http://localhost:5173` and expects the API on
`http://localhost:8000` unless `VITE_API_URL` says otherwise. Register a user
from the sign-up screen; the backend seeds a shared set of categories, so the
only thing to do first is add an account.

```bash
npm run typecheck   # tsc --noEmit across the project references
npm run build       # production bundle in frontend/dist/
npm run preview     # serve that bundle locally
```
