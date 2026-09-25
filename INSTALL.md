# Installing the Square Sync module

A self-contained admin module for a Laravel + Vue/Inertia app that follows
the [admin module conventions](https://github.com/charybshawn/cultpantry-shop-front/blob/main/docs/ADMIN_MODULE_AUTHORING.md)
used by [Cult Pantry](https://github.com/charybshawn/cultpantry-shop-front) --
`packages/{vendor}/{module}/` layout, a `module.json` manifest, and
Composer/Inertia auto-discovery. It should work in any app built on that same
convention.

## 1. Clone this repo into your app

```bash
git clone https://github.com/charybshawn/modules-square-sync.git packages/cultpantry/square-sync
```

Your app's root `composer.json` needs a path repository covering
`packages/*/*` (Cult Pantry already has this by default):

```json
"repositories": [
    { "type": "path", "url": "packages/*/*" }
]
```

## 2. Install the package

```bash
composer require cultpantry/square-sync:@dev
```

## 3. Configure

Copy the required environment variables into `.env` -- see
`config/square-sync.php` for the full list (`SQUARE_ACCESS_TOKEN`,
`SQUARE_WEBHOOK_SIGNATURE_KEY`, `SQUARE_ENVIRONMENT`, `SQUARE_NOTIFICATION_URL`,
etc.). Every credential is read via `env()`; nothing is ever committed.

## 4. Migrate

```bash
php artisan migrate
```

Creates `square_object_mappings`, `square_webhook_events`,
`square_inventory_changes`, and `square_imported_sales`. The last two
migrations also seed the change-log and sales watermarks to "now": the
sync picks up from the moment you deploy.

## 5. Bind the integration contracts

The module never touches your app's models directly. It talks to your app
only through the interfaces in `src/Contracts/`, which your app binds in a
service provider of its own. Cult Pantry's is
`App\Providers\SquareSyncIntegrationServiceProvider`:

| Contract | What it's for |
|---|---|
| `LocalCatalog` | Look up your sellable items (e.g. products) by id or SKU |
| `LocalInventory` | Apply a Square sale / restock to local stock through your own stock funnel |
| `AuditLog` | Write and read back the `square.*` audit trail |
| `SquareSaleRecorder` | Record each Square sale and refund in your own orders system (record-only -- must not touch stock) |
| `AdminAlerts` | Tell your admins (e.g. by email) when the sync goes offline, a link goes stale, or it all recovers |

Every contract has a null default, so an app that binds nothing still boots,
but syncs and records nothing.

Your app also tells the module about local changes:

- On every local stock change, call `Cultpantry\SquareSync\Actions\QueueStockPush::handle($itemId, $newQuantity)`.
  Skip changes that came from `LocalInventory::setFromSquareAtLink()`.
- When an item's title, description, or price changes, or it's archived or restored, call
  `QueueCatalogPush::handle($itemId, QueueCatalogPush::UPDATED | ARCHIVED | RESTORED)`.

## 6. Square account setup

The access token needs these permissions: `ITEMS_READ`, `ITEMS_WRITE`,
`INVENTORY_READ`, `INVENTORY_WRITE`, `ORDERS_READ`, `CUSTOMERS_READ`. The
sandbox test sale also needs `ORDERS_WRITE` and `PAYMENTS_WRITE`. Use the
application's personal access token if you can: it's the only kind that can
read webhook subscriptions, so Diagnostics can check and test them.

Webhook subscriptions (Square Developer Console):

- `inventory.count.updated` -- required. Applies Square sales to local stock
  and overwrites any count changed directly on Square.
- `catalog.version.updated` -- required. Picks up items deleted on Square.
- `order.updated` -- optional. Records sales of items that don't track
  inventory within seconds, instead of at the next scheduled pull.

The module schedules its own commands -- your app only needs the usual
`schedule:run` cron:

- `square:check`, every 15 minutes: checks the connection and every product
  link, and alerts admins when something breaks or recovers.
- `square:pull-sales`, every 15 minutes: catch-up for any missed webhook.
- `square:reconcile`, hourly: drift report (report-only).

Set `SQUARE_SCHEDULE=false` if your app schedules them itself.

## 7. Backfill past Square sales (once)

```bash
php artisan square:import-sales --since=2026-01-01
```

This records sales and refunds only; it never changes stock, because those
sales already happened. It's safe to re-run, and re-running it is how you
retry any order logged as `square.sale_import_failed`.

## 8. Publish the Vue pages and build

```bash
php artisan vendor:publish --tag=square-sync-pages
npm run build   # or npm run dev while iterating
```

## 9. Verify

Then visit `/admin/settings/modules` as an admin -- Square Sync should show
an "Active" badge. Register the webhook notification URL
(`SQUARE_NOTIFICATION_URL`) in the Square Developer Console, matching byte
for byte -- Square signs its webhook payloads over that exact URL plus the
raw body, so any mismatch fails every signature check.

Open `/admin/square` and use **Diagnostics**:

- **Run Checks** walks every step a Square sale takes to reach local
  stock: connection, permissions, location, links, webhook settings, the
  webhook subscription on Square, a live delivery test (Square sends this
  app a sample event), and recent sync activity.
- **Test sale** (sandbox only) rings up one unit of a linked product on
  Square, then follows it back: order completed, webhook received, local
  stock down by one, sale recorded. The unit is put back afterwards.

## How the sync behaves

- **Local stock is the source of truth.** Local adjustments (stock added,
  removed, recounted, production runs, web orders) are pushed to Square as
  absolute counts.
- **Only Square sales move local stock.** The inventory webhook triggers a
  read of Square's inventory change log. Movements out of `IN_STOCK` into a
  sale state (`SOLD`, `RESERVED_FOR_SALE`) lower local stock. Refunds and
  returns back into `IN_STOCK` raise it. Each change is applied exactly once.
- **Manual edits on Square are ignored and overwritten.** A recount, waste
  entry, or any other count change made on Square is never applied locally.
  The local count is pushed back over it, and a
  `square.manual_change_overridden` event is logged.
- **Drift is fixed in one direction.** `square:reconcile --fix` and the admin
  page's per-row resolve push local counts to Square.
- **The connection is checked live.** Opening the Square Sync page, Run
  Sync Check, and `square:check` every 15 minutes each ask Square which
  account the token belongs to, whether it's still valid, has the
  permissions the sync needs, and whether the sync location is active --
  then look up every linked item. The page shows the connection as Online
  or Offline with what's wrong, and a check older than an hour is flagged
  (the scheduler isn't running).
- **Changing Square accounts resets the sync.** Links are only meaningful
  on the account they were made on. Switching `SQUARE_ENVIRONMENT` (caught
  before the next API call) or pointing the token at a different seller
  (caught by the next check) unlinks every product, clears the in-app sync
  location, restarts the catalog, inventory and sales watermarks, and logs
  `square.account_changed`. Then relink products against the new account.
  Sales recorded from the sandbox are tagged `environment: sandbox`.
- **Stale links go offline, never removed.** Each check marks every link
  Online, Offline (its Square item no longer exists on this account --
  it stops syncing), Archived, or Not at location. While the connection
  itself is down, every link shows Offline. A link whose item comes back
  is online again on the next check.
- **Admins are told when it changes.** When a check finds something new
  wrong -- or everything recovers -- it's logged (`square.health_degraded`
  / `square.health_restored`) and sent through `AdminAlerts`. Checks that
  find nothing new stay quiet.
