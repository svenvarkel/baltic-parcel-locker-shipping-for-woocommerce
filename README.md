# Baltic Parcel Locker Shipping for WooCommerce

Omniva and SmartPost (Posti / SmartPosti) parcel lockers for WooCommerce:

- **Checkout**: shipping methods with a parcel-locker picker (grouped by city), for
  Omniva → EE / LV / LT and SmartPost → EE / FI.
- **Order admin**: one click registers the shipment with the carrier, stores the barcode
  (and SmartPost door code), and a second click opens the printable PDF label.
- **Settings**: carrier credentials are stored encrypted (libsodium), never echoed back to the form.

> **Status: v0.1.0 — not yet run against the real carrier APIs.** Everything was built from the
> carriers' public documentation and tested end-to-end against a local mock. See
> [Open questions](#open-questions) before trusting it with real parcels.

## How this was written

100% of the code, tests, translation and this README were written by an AI coding agent
(Claude Code, Claude Opus 5.5), directed by a human ([@svenvarkel](https://github.com/svenvarkel))
who set the goal and the constraints but did not write code. The implementation plan and the
diff were reviewed by a second model (OpenAI Codex); its findings were checked against the code
and fixed before the first commit. Critique is welcome — open an issue.

Every prompt the human typed, verbatim and timestamped: **[CASE-STUDY.md](CASE-STUDY.md)**
(3 h 21 min from first prompt to production).

## Requirements

- WordPress 6.5+, WooCommerce 7.4+ (developed against WP 7.1 / WC 11.2), PHP 8.1+ with `sodium`
- Classic (shortcode) checkout. The block checkout is **not** supported yet.
- Omniva: business-customer contract + OMX API user/password (from your Omniva account manager or business support).
  The **customer code** is usually the same as the API username — it is not shown anywhere in Omniva self-service.
- SmartPost: business postpaid account; API key from SmartPosti self-service → Settings (account owner only).

## Install

```
cd wp-content/plugins
git clone https://github.com/svenvarkel/baltic-parcel-locker-shipping-for-woocommerce.git
```

Activate, then:

1. **WooCommerce → Settings → Baltic Parcel Locker Shipping**: carrier credentials and sender details.
2. **WooCommerce → Settings → Shipping → zone → Add method**: "Omniva parcel machine" / "SmartPost parcel machine";
   per instance set title, cost, free-from amount, tax status.

Optional: define `BPL_SHIPPING_KEY` in `wp-config.php` to use your own encryption key instead of `wp_salt('secure_auth')`.

## Design notes

**Code map** (~1150 lines, no dependencies):

| File | What |
|---|---|
| `includes/class-bpl-carriers.php` | Terminal lists, payload builders, response parsers, HTTP. Builders/parsers are pure functions. |
| `includes/class-bpl-shipping-method.php` | The two `WC_Shipping_Method`s, checkout picker, validation, order meta. |
| `includes/class-bpl-admin.php` | Order metabox, create / label / release-lock handlers. |
| `includes/class-bpl-settings.php` | Settings tab, secret field type. |
| `includes/class-bpl-crypto.php` | `sodium_crypto_secretbox` wrapper (`bpl1:` prefix). |

**Never create a parcel twice.** Creating a shipment is not idempotent at either carrier, so:

- An atomic per-order lock (`INSERT IGNORE` into `wp_options`) is taken first; the order is then re-read
  from the database (bypassing WC's meta cache) and rechecked for an existing barcode.
- Outcomes are `created`, `failed` or `unknown`. Only a definite rejection (4xx, Omniva `failedShipments`,
  DNS/connect error) is `failed` and releases the lock. Timeouts, dropped connections, 3xx, 5xx and
  unparseable 2xx are `unknown`: the order stays locked and the admin is told to check carrier
  self-service before pressing **Release lock**.
- POST requests don't follow redirects. A lock older than 120 s also offers Release lock (covers a PHP fatal mid-request).

**Secrets** are encrypted with a key derived from WP salts (or `BPL_SHIPPING_KEY`). This protects DB dumps
and backups, not a fully compromised server — the key lives next to the database.

**Labels** are proxied straight from the carrier to the browser and never stored (they contain personal data).

**Terminal lists** come from public endpoints (`omniva.ee/locations.json`, `my.smartposti.ee` Open API), cached
24 h, with the last good copy kept for outages.

## Development

- `php tests/selfcheck.php` — 43 plain asserts, no WordPress needed (payloads, parsers, phone normalisation, crypto).
- `dev/bpl-carrier-mock.php` — drop into `wp-content/mu-plugins/` on a local site
  (`WP_ENVIRONMENT_TYPE=local` or a `*.local` host). Intercepts carrier HTTP calls and logs the payloads
  (WooCommerce → Status → Logs, source `bpl-carrier-mock`). Option `bpl_mock_mode` switches responses:
  `ok`, `http500`, `timeout`, `omniva_failed`, `garbage`, `smartpost_label_url`.

## Open questions

Things the documentation doesn't settle; they need a real account to verify:

1. **Omniva label request shape**: the PDF manual shows `barcodes: ["…"]`, the OpenAPI schema `[{barcode}]`.
   The plugin sends strings and retries once with objects on a 4xx.
2. **Omniva "pakikood"** (drop-off code, label printed at the machine): no dedicated API service is documented.
   The "registration SMS" setting sends `notifications: [{type: REGISTERED, channel: sms}]`; whether that SMS
   carries a usable drop-off code is unverified.
3. **SmartPost `additionalservices.labelprinted`** is not sent; if shipments show up as "awaiting label" in
   self-service, it may be needed.
4. **Omniva `X-Integration-Agent-Id`** header is not sent (the value has to be issued by Omniva).

## Carrier documentation

- Omniva OMX: <https://developer.omniva.ee/>, <https://www.omniva.ee/ariklient/api/dokumentatsioon/>
- SmartPosti API v1.7.1 (PDF) — linked from SmartPosti business self-service.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
