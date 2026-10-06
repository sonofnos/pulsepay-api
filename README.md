# PulsePay API

Laravel backend for a crypto/fintech wallet app: accounts, a double-entry
ledger, P2P crypto trading with escrow, webhook-driven deposits, and bill
payment. Built as a focused piece, not a CRUD demo. The point was the parts
of a money-moving backend that break under concurrency and retries if you
don't handle them deliberately.

Stack: Laravel 13, Postgres, Redis (predis), Sanctum. 18 feature tests, all
against real Postgres/Redis in CI. Nothing's mocked.

## The ledger

`LedgerService::post()` is where every balance change happens. Transfers,
deposits, withdrawals, P2P escrow, and bill payments all go through it. It
locks both wallets with `SELECT ... FOR UPDATE` in ascending id order (not
from/to order), so two transfers sharing a wallet can't deadlock each other,
and it writes a balanced debit/credit pair to `ledger_entries` instead of
just mutating a balance column. `reconstructBalance()` rebuilds a wallet's
balance purely from that history, as a check that the cached column isn't
lying.

I didn't just trust the locking logic on paper. `scripts/concurrency_demo.sh`
fires 15 concurrent transfer requests at a wallet that only has enough
balance for one of them, against a real running server backed by real
Postgres, and checks that exactly one succeeds and the balance never goes
negative. It runs in CI on every push:

```
Successful transfers: 1 / 15 (expected: 1)
Final spender NGN balance: 0 (expected: 0, never negative)
PASS
```

## Idempotency and escrow

Money-moving routes require an `Idempotency-Key` header (`EnsureIdempotent`
middleware, Redis-backed). Replay the same key with the same body and you
get the cached response back, not a second transaction. Reuse the key with a
different body and you get a 409.

P2P trades lock the seller's crypto into a shared escrow wallet the instant
a trade opens (`P2pTradeService`). It only leaves escrow by being released
to the buyer or refunded on cancellation. There's no code path where it just
disappears.

## Running it

```bash
cp .env.example .env
docker compose up -d
composer install
php artisan key:generate
php artisan migrate
php artisan serve
php artisan test
```

## Things that broke while building this

A guest request without an `Accept: application/json` header crashed with a
500 instead of a 401. Laravel's default `Authenticate` middleware tries to
redirect to a `login` route that doesn't exist in an API-only app. Every
test I'd written used `getJson()`, which sets that header automatically, so
it never showed up until I hit the endpoint with plain curl. Fixed with
`redirectGuestsTo(fn () => null)` in `bootstrap/app.php`, plus a test that
deliberately omits the header so it can't come back silently.

The webhook handler's dedup logic had two layered races. First,
`firstOrCreate` on `(provider, event_id)` isn't actually safe: two
concurrent deliveries of the same event can both pass the `SELECT` before
either inserts. Switched to `createOrFirst`, which catches that. But then
both requests could still see `processed_at IS NULL` and both apply the
deposit, so I also had to lock the `webhook_events` row for the whole
check-then-apply sequence, not just the insert.

`composer create-project laravel/laravel ... "^11"` refused to install
outright over open security advisories on that version range. Dropped the
version pin and it resolved to Laravel 13 clean.

No `phpredis` extension on this machine (fresh Homebrew PHP install), so I
used `predis` instead, a pure-PHP Redis client with no system extension to
install at all.

## Not in scope

No KYC, no real payment-rail integration (the VTU provider is a documented
fake behind a `VtuProvider` interface; swapping in Reloadly or VTpass is a
binding change, not a rewrite), no rate limiting beyond Laravel's defaults.
