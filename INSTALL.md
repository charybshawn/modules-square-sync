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

Every contract has a null default, so an app that binds nothing still boots,
but syncs and records nothing.

Your app also tells the module about local changes:

- On every local stock change, call `Cultpantry\SquareSync\Actions\QueueStockPush::handle($itemId, $newQuantity)`.
  Skip changes that came from `LocalInventory::setFromSquareAtLink()`.
- When an item's title, description, or price changes, or it's archived or restored, call
  `QueueCatalogPush::handle($itemId, QueueCatalogPush::UPDATED | ARCHIVED | RESTORED)`.

## 6. Square account setup

The access token needs these permissions: `ITEMS_READ`, `ITEMS_WRITE`,
`INVENTORY_READ`, `INVENTORY_WRITE`, `ORDERS_READ`, `CUSTOMERS_READ`.

Webhook subscriptions (Square Developer Console):

- `inventory.count.updated` -- required. Applies Square sales to local stock
  and overwrites any count changed directly on Square.
- `catalog.version.updated` -- required. Picks up items deleted on Square.
- `order.updated` -- optional. Records sales of items that don't track
  inventory within seconds, instead of at the next scheduled pull.

Schedule `square:pull-sales` (e.g. every 15 minutes) as a catch-up for any
missed webhook, `square:verify-links` (e.g. hourly) to catch product links
whose Square item was deleted, archived, or taken off the sync location, and
optionally `square:reconcile` for a drift report.

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
- **Switching between sandbox and production resets the sync.** They're
  separate Square accounts, so the first request after `SQUARE_ENVIRONMENT`
  changes does four things: it unlinks every product, clears the in-app sync
  location, restarts the catalog, inventory and sales watermarks at the time
  of the switch, and logs `square.environment_changed`. After that, relink
  products against the new account. Sales recorded from the sandbox are
  tagged `environment: sandbox`.
- **Stale links are flagged, never removed.** `square:verify-links`, which
  also runs as part of the admin page's Run Sync Check, looks up every linked
  Square item and marks each link OK, Missing, Archived or Not at location.
  Missing links stop syncing until they're unlinked or relinked. A link whose
  item comes back is live again on the next check.
