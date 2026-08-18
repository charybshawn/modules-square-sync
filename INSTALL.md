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

Creates `square_object_mappings` and `square_webhook_events`.

## 5. Publish the Vue pages and build

```bash
php artisan vendor:publish --tag=square-sync-pages
npm run build   # or npm run dev while iterating
```

## 6. Verify

Then visit `/admin/settings/modules` as an admin -- Square Sync should show
an "Active" badge. Register the webhook notification URL
(`SQUARE_NOTIFICATION_URL`) in the Square Developer Console, matching byte
for byte -- Square signs its webhook payloads over that exact URL plus the
raw body, so any mismatch fails every signature check.
