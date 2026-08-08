# Deployment & Operations

---

## WPCode Workflow

All PHP, JS, and CSS is deployed as **WPCode snippets** in WordPress.

### Load Order (Critical)

1. **Reference Data PHP** — must be first. Defines functions other snippets call.
2. **Index Backend PHP** — depends on Reference Data functions.
3. **Frontend Shortcode PHP** — depends on index backend being registered.
4. **Common CSS** — site-wide, scoped under `.biw`.
5. **Common JS** — site-wide, auto-boots widgets.

### Snippet Settings

| File | Snippet Type | Location | Priority |
|---|---|---|---|
| Reference Data PHP | PHP | Run Everywhere | 1 |
| Index Backend PHP | PHP | Run Everywhere | 2 |
| Shortcode PHP | PHP | Run Everywhere | 3 |
| Common CSS | CSS | Site Wide Header | 4 |
| Common JS | JS | Site Wide Footer | 5 |

**Why this order matters:** If the index backend loads before Reference Data, `function_exists('blomstra_get_global_country_list')` returns false and the backend uses its fallback path unnecessarily.

---

## Required wp-config.php Definitions

```php
// UN Comtrade API key
define('COMTRADE_PRIMARY_KEY', 'your-un-comtrade-key-here');

// EIA API key
define('EIA_API_KEY', 'your-eia-key-here');
```

Without these:
- Comtrade fallback returns error: "COMTRADE_PRIMARY_KEY not defined"
- EIA fallback returns error: "API key missing"
- Central paths may still work if Reference Data has cached data

---

## Cron Health Monitoring

Two independent signals track cron health — see [`01-architecture.md`](01-architecture.md)'s build-reliability section for why they're kept deliberately separate:

### Signal 1: {slug}_cron_status (option)
Written by both real cron and "Force Run Now" test button.
```json
{
  "time": "2026-08-05 02:00:00",
  "status": "success",
  "details": "Composite built from central cache with 187 countries."
}
```

### Signal 2: {slug}_last_wpcron_fired (option)
Written **only** by the real `{slug}_daily_cron` hook. Cannot be faked by the test button.

**Admin notice fires if:**
- `{slug}_last_wpcron_fired` is null (never fired)
- Age > 30 hours (stale schedule)

### Fixing Broken wp-cron

Common on low-traffic sites or hosts with disabled `wp-cron.php`:

**Option A: Real system cron**
```bash
# Add to crontab
*/5 * * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

**Option B: WP-CLI**
```bash
# Add to crontab
0 2 * * * cd /var/www/html && wp cron event run --due-now
```

---

## Database Schema

### Custom Table: wp_blomstra_index_history

Created automatically on first admin page load via `dbDelta()`. See [`08-reference-data-functions.md`](08-reference-data-functions.md) for the save/get functions that read and write this table.

```sql
CREATE TABLE wp_blomstra_index_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  index_slug VARCHAR(40) NOT NULL,
  iso3 VARCHAR(3) NOT NULL,
  snapshot_period VARCHAR(7) NOT NULL,
  composite_score DECIMAL(6,2) DEFAULT NULL,
  rank_value SMALLINT UNSIGNED DEFAULT NULL,
  coverage_type VARCHAR(10) DEFAULT NULL,
  pillars_json LONGTEXT DEFAULT NULL,
  recorded_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY idx_slug_iso_period (index_slug, iso3, snapshot_period),
  KEY idx_slug_period (index_slug, snapshot_period)
);
```

**Upsert semantics:** `INSERT ... ON DUPLICATE KEY UPDATE`. Rebuilding the same index twice in one month updates the existing row — verified live: rebuilding CII twice in one day kept the country count fixed while `recorded_at` advanced, confirming update-in-place rather than duplication.

---

## Troubleshooting Guide

| Symptom | Likely Cause | Fix |
|---|---|---|
| "Index not built yet" (404) | Composite never built | Run "Refresh All & Build" on admin page |
| All ranks show "NEW" | No history snapshots yet | Build composite twice (across two months) or wait for monthly rollover |
| Pillar shows "Never refreshed" | Reference Data cron not firing | Check wp-cron; use manual refresh buttons |
| "Quota exhausted" on HHI | Comtrade monthly limit reached | Wait for next month; use cached data |
| Frontend table empty | JS engine not loaded | Verify Common JS snippet is active and loaded |
| Rank changes when filtering | Frontend bug (old versions) | Ensure rank comes from API, not array index — see [`04-frontend-engine.md`](04-frontend-engine.md) |
| Admin page 500 error | PHP memory limit | Increase `memory_limit` in php.ini |
| Build lock stuck | Previous build crashed | Wait 5 minutes (TTL) or manually clear transient — it self-heals, see [`01-architecture.md`](01-architecture.md) |
| Partial countries all show same range | Injection simulation bug | Verify `pillar_weight_by_name` sums correctly; check BMS-002 algorithm |
| Table has a stray border on two sides only | Theme's own `table { border }` leaking through `border-collapse` | Give `.biw-table` an explicit `border: none` — see [`04-frontend-engine.md`](04-frontend-engine.md) |
| Dropdown list has a white background | Native OS popup ignoring CSS | Known cross-browser limitation on some Windows Chrome builds — see [`04-frontend-engine.md`](04-frontend-engine.md) |
| GNI→GDP fallback double-counting | GDP growth still scored after fallback | Ensure GDP is skipped when `macro_base_source === 'gdp_fallback'` — see GERI reference |
| Inflation threshold not applied | Missing `geri_adjust_inflation_percentile()` call | Apply after percentile computation, before composite build |
| Partial rank shows single point | Injection uses same composite for all percentiles | Implement real BMS-002 algorithm; see [`10-engineering-research-standards.md`](10-engineering-research-standards.md) |

---

## Backup & Recovery

### What to Back Up

1. **WPCode snippets** — export from WPCode admin
2. **WordPress options** — `{slug}_*`, `blomstra_*` options contain all computed data
3. **Custom table** — `wp_blomstra_index_history` for historical trends
4. **wp-config.php** — API keys

### Recovery Procedure

1. Re-install snippets in correct load order
2. Re-define API keys in wp-config.php
3. Run "Refresh All & Build" from admin page
4. Verify REST endpoint returns data
5. Check frontend renders correctly

**Note:** All raw data can be re-fetched from external APIs. The only irreplaceable data is `wp_blomstra_index_history` (historical snapshots) — there is no way to backfill a missed month after the fact, which is why this table is worth backing up more carefully than anything else in this list.

---

## Performance Notes

| Operation | Typical Time | Bottleneck |
|---|---|---|
| Maritime refresh | 5–10 seconds | World Bank API response |
| EIA refresh (global) | 10–15 minutes | 5 fuels × 2 activities × ~200 countries / 25 per chunk |
| HHI refresh (global) | 15–30 minutes | Comtrade pagination + rate limits |
| WB indicator refresh (bulk) | 30–60 seconds | `per_page=20000` single call |
| IMF forecast refresh | 5–10 seconds | Per-indicator API call |
| Composite build | < 1 second | Pure PHP calculation |
| Frontend load | < 2 seconds | 3 parallel REST requests |

Figures above are approximate, order-of-magnitude guidance rather than precisely benchmarked numbers — worth treating as a rough expectation-setter, not an SLA.

**Optimization:** The daily cron uses `central_cached` (reads options only), so it completes in under 1 second regardless of API speed.

---

## Multi-Index Operational Notes

When running multiple indices on the same site:

1. **Stagger weekly crons** — each index's data collection should run on a different day to avoid hitting the same API rate limits simultaneously
2. **Isolate option keys** — each index MUST use its own option key prefix (e.g., `cii_`, `geri_`) to prevent collision
3. **Isolate cron hooks** — each index MUST use unique cron hook names
4. **Shared Reference Data** — one Reference Data snippet serves all indices; do not duplicate
5. **Snapshot table** — `wp_blomstra_index_history` is shared; the `index_slug` column separates indices

---

## What to read next

- System architecture → [`01-architecture.md`](01-architecture.md)
- Frontend behavior → [`04-frontend-engine.md`](04-frontend-engine.md)
- Building a new index → [`05-index-template.md`](05-index-template.md)
