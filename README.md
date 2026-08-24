# Expense Tracker

A full-stack expense tracking app: register, log in, and log your day-to-day
spending against categories, with a dashboard that breaks totals down by
category. Built as a portfolio piece to show a hand-rolled PHP backend (no
framework) paired with a React/TypeScript frontend.

## Stack

- **Backend:** PHP 8, no framework — a small front-controller router
  (`backend/src/Router.php`) dispatches to plain controller classes. Auth is
  JWT-based (`firebase/php-jwt`), passwords are hashed with `password_hash`
  (bcrypt). Storage is SQLite via PDO, so there's no separate database server
  to stand up.
- **Frontend:** React + TypeScript, built with Vite.
- **Tests:** PHPUnit — unit tests for the validation/JWT logic, plus
  integration tests that run real CRUD against a temporary SQLite file.

Why no PHP framework? This is a portfolio project, and hand-rolling the
routing/request/response layer is a more direct way to show how the pieces
fit together than `php artisan make:controller` would be. The tradeoff is
obvious — a real production API would probably reach for Laravel or Symfony
— but for a project this size the plain version stays readable end to end.

## Features

- Registration and login, JWT issued on success and required (as a Bearer
  token) on every expense endpoint.
- CRUD for expenses (amount, category, description, date), scoped to the
  logged-in user.
- Filtering the expense list by category and/or date range.
- A summary endpoint that totals expenses per category for the current user.
- A dashboard UI: add/edit/delete expenses inline, filter the list, and see
  a category breakdown as a plain-CSS bar list (no charting library).
- Consistent JSON error responses with correct HTTP status codes (400 for
  bad input shape, 401 for missing/invalid auth, 404 for a missing/foreign
  expense, 409 for a duplicate email, 422 for failed validation).

## Project layout

```
backend/    PHP API (public/index.php is the entry point)
frontend/   React + TypeScript app (Vite)
```

## Running the backend

Requires PHP 8.1+ and Composer.

```bash
cd backend
composer install
cp .env.example .env    # optional — sane defaults are used if you skip this
php -S localhost:8000 -t public
```

The API is now at `http://localhost:8000`. On first request it creates
`backend/database/expenses.sqlite` and runs the schema automatically — no
manual migration step.

To run the test suite:

```bash
cd backend
vendor/bin/phpunit
```

### API summary

| Method | Path                | Auth | Notes                                   |
|--------|---------------------|------|------------------------------------------|
| POST   | `/api/register`     | no   | `name`, `email`, `password` (8+ chars)   |
| POST   | `/api/login`        | no   | `email`, `password`                      |
| GET    | `/api/expenses`     | yes  | optional `?category=&from=&to=`          |
| POST   | `/api/expenses`     | yes  | `amount`, `category`, `expense_date`, optional `description` |
| PUT    | `/api/expenses/{id}`| yes  | any subset of the fields above           |
| DELETE | `/api/expenses/{id}`| yes  |                                           |
| GET    | `/api/summary`      | yes  | totals grouped by category               |

Authenticated requests send `Authorization: Bearer <token>`.

## Running the frontend

Requires Node 18+.

```bash
cd frontend
npm install
cp .env.example .env    # set VITE_API_URL if the backend isn't on :8000
npm run dev
```

`npm run build` produces a production bundle in `frontend/dist/`;
`npm run preview` serves that bundle locally.

## Notes on how this was built

This project was scaffolded in a sandboxed environment with no access to
the npm or Packagist registries, which shaped two things worth calling out
if you're reading the source:

- **Backend:** `composer.json` requires `firebase/php-jwt` and
  `phpunit/phpunit` the normal way. In the sandbox, Composer couldn't reach
  Packagist, so those packages (and their transitive dependencies) were
  resolved as Git VCS sources instead — `composer.lock` reflects that, but
  it's a completely ordinary lock file and `composer install` works the
  standard way on a machine with normal registry access.
- **Frontend:** the sandbox also couldn't reach the npm registry, so real
  `vite` and `@vitejs/plugin-react` couldn't be installed. `package.json`
  declares them normally, and on any machine with npm access `npm install`
  will resolve the genuine packages with no code changes needed. Since
  `node_modules/` isn't committed, this doesn't affect anyone cloning the
  repo.
