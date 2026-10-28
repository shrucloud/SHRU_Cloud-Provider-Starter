# SHRU_Cloud-Provider-Starter

A reference PHP implementation of a **SHRU Cloud** provider (S-SERVER) - run your own supplier panel that speaks the SHRU protocol, so any SHRU Cloud platform can connect, sync your catalog, and place orders through it.

Plain PHP, no database, no Composer dependencies - services, credentials, and orders are stored as JSON files under `data/`. Meant as a working starting point to adapt, not a finished platform: fork it, wire `submit_order`/order fulfillment into your own backend, and go from there.

**Contents:** [What's included](#whats-included) · [Requirements](#requirements) · [Setup](#setup) · [Using the admin panel](#using-the-admin-panel) · [The API](#the-api) · [Trying it with a reseller](#trying-it-with-a-reseller) · [Security notes](#security-notes)

## What's included

- **Admin panel** (`public/admin/`) - Dashboard, Services, Credentials, Orders.
- **The public API** - `POST /api/shru`, implementing `account_info`, `sync`, `submit_order`, `check_status`.
- **JSON file storage** (`storage.php`) - atomic writes (write-to-temp-then-rename) and one store lock around every change, no database server required.

Any client speaking the SHRU protocol can talk to this - [SHRU_Cloud-PHP-SDK](https://github.com/shrucloud/SHRU_Cloud-PHP-SDK), [SHRU_Cloud-Reseller-Starter](https://github.com/shrucloud/SHRU_Cloud-Reseller-Starter), or a full SHRU Cloud platform's own supplier sync.

## Requirements

- PHP 7.4 or newer (the admin pages use arrow functions). **Recommended: PHP 8.5.12 or newer**, which includes the fixes for CVE-2026-109618 and CVE-2026-109619 (PHP2Shell). Older branches are fixed in 8.4.27, 8.3.36 and 8.2.35; PHP 8.1 and older no longer receive regular security updates.
- Apache with `mod_rewrite` (a `.htaccess` is included, see step 4 below), or an equivalent rewrite rule on another webserver
- HTTPS in production - the API refuses plain HTTP requests outright

## Setup

1. **Configure.** Copy `config.example.php` to `config.php` and set `currency` and `admin_username`.

2. **Set an admin password.** The admin panel refuses to run at all until `admin_password_hash` is a real hash - there's no default password to forget to change:

   ```bash
   php generate-admin-password.php
   ```

   Paste the printed hash into `config.php`'s `admin_password_hash`. Save the printed password itself somewhere safe (a password manager, not your terminal scrollback) - it's what you actually log in with, and it's never shown again.

3. **Point your webserver's document root at `public/`.** `config.php` and `storage.php` stay outside the web root either way (they already do, one level up) - `config.php` holds a real secret and must never be directly servable, even by misconfiguration.

4. **Confirm routing.** The protocol is fixed at exactly `/api/shru`, no trailing slash. The included `.htaccess` routes that to `public/_internal/api-shru.php` rather than a real `public/api/shru/index.php` file - a physical path there would collide with the URL itself and get redirected by Apache's own directory-slash handling before the rewrite rule ever runs, and a signed HMAC request is never safe to blindly redirect-and-retry. On a different webserver, replicate that one rewrite rule. A SHRU Cloud platform that adds you as a SHRU supplier only needs your domain (`https://your-domain.com`); it finds `/api/shru` on its own.

5. **Log in** at `public/admin/` and add at least one service and one credential before a reseller can sync anything.

`data/` is created automatically on first write and is already gitignored - nothing to set up there.

## Using the admin panel

- **Services** - name, group, type (`Fixed` = one input per order, eg. IMEI/SN/ECID/account ID; `Quantity` = bought by count), cost/price, delivery estimate, the primary input's label, up to 10 extra fields (text or textarea, each optionally required), and for a Fixed service, whether it accepts bulk ordering. Only an **Active** service is visible to resellers via `sync`.
- **Credentials** - one Key ID + HMAC secret per reseller, each with its own starting balance and IP allowlist. The secret is shown exactly once at creation, copy it immediately. A credential with an empty allowlist blocks every request until you add at least one IP/CIDR. To add or take away funds later, use **Adjust balance** on the credential's edit page.
- **Orders** - queued orders land here as resellers place them. Setting a status to **Rejected** or **Refunded** credits the price back to that reseller's balance once and closes the order. The **Reply** field is what a reseller's `check_status` call receives back, as plain text - eg. the unlock code or result.

## The API

`POST /api/shru`, JSON body, one `action` per request - `account_info`, `sync`, `submit_order`, `check_status`. Requests are authenticated with `X-Key-Id`/`X-Timestamp`/`X-Signature` headers (HMAC-SHA256), the same protocol on every SHRU Cloud platform. See [SHRU_Cloud-PHP-SDK's README](https://github.com/shrucloud/SHRU_Cloud-PHP-SDK#protocol-reference) for the full signing algorithm and every action's exact request/response shape - this server implements that spec exactly, so anything built with the SDK works here unchanged.

## Trying it with a reseller

1. Create a service and a credential in the admin panel.
2. Point [SHRU_Cloud-Reseller-Starter](https://github.com/shrucloud/SHRU_Cloud-Reseller-Starter)'s `config.php` (or a quick script using [SHRU_Cloud-PHP-SDK](https://github.com/shrucloud/SHRU_Cloud-PHP-SDK) directly) at this server's base URL, with that credential's Key ID/secret.
3. Load its catalog page (or run `examples/sync-services.php`) - your service should appear with its price, input label, and any extra fields.
4. Place a test order - it shows up under **Orders** here, ready to reply to.

## Security notes

- HTTPS is required; a request over plain HTTP is rejected before authentication is even checked.
- IP resolution and the HTTPS check are both Cloudflare-aware - `resolveRealClientIp()`/`shruRequestIsSecure()` in `bootstrap.php` only trust Cloudflare's forwarded-IP and visitor-scheme headers after independently verifying the connection actually came from a genuine Cloudflare edge IP, never from the header alone.
- Every change to services, credentials, balances and orders runs under one store lock on freshly read data, so API orders and admin actions never overwrite each other.
- Requests are size-limited and every input is checked (valid UTF-8, length, whole-number quantity within the service's range). The exact same signed request is never accepted twice.
- Every admin form is CSRF-protected (`checkCsrf()` in `public/admin/_auth.php`). Admin sessions use HttpOnly, SameSite=Strict cookies and end after 30 idle minutes; repeated failed sign-ins from one address are paused for 15 minutes.
- Each reseller credential's IP allowlist is enforced on every API call, not just at creation.
- `data/` carries its own deny rule, so it is never served even if the document root is set too high.

## License

MIT - see [LICENSE](LICENSE).
