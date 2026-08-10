# Deployment

> **Applies to:** All indices
> **Platform:** WordPress + WPCode (or equivalent snippet manager)

---

## Prerequisites

- WordPress 6.0+ with `wp_remote_get()` enabled (most hosts)
- WPCode plugin (or any PHP snippet manager that supports "Run Everywhere")
- API keys:
  ```php
  // wp-config.php
  define('COMTRADE_PRIMARY_KEY', 'your-un-comtrade-key');
  define('EIA_API_KEY', 'your-eia-key');
  ```
- Optional but recommended: WP Crontrol plugin for cron debugging

---

## Installation Order

**Order matters.** Install in this sequence:

1. **Shared Utilities** -- `src/shared/blomstra-index-utilities.php` -> WPCode -> Run Everywhere
2. **Index Backend** -- e.g., `src/indices/seri/seri-backend.php` -> WPCode -> Run Everywhere
3. **Index Shortcode** -- e.g., `src/indices/seri/seri-shortcode.php` -> WPCode -> Run Everywhere
4. **Frontend CSS** -- `src/frontend/index-frontend-styles.css` -> WPCode -> Site Wide Header
5. **Frontend JS** -- `src/frontend/index-frontend-engine.js` -> WPCode -> Site Wide Footer

**Why this order:** The backend registers REST endpoints and admin menus on `init`. The shortcode registers on `init`. The frontend loads after the DOM. If you install the backend before utilities, it will fail validation and may wp_die() in debug mode.

---

## WPCode Configuration

### Shared Utilities Snippet

- **Title:** Blomstra Shared Utilities
- **Code Type:** PHP Snippet
- **Location:** Run Everywhere
- **Priority:** 5 (load before indices)

### Index Backend Snippet

- **Title:** SERI Backend (or SIVI Backend)
- **Code Type:** PHP Snippet
- **Location:** Run Everywhere
- **Priority:** 10

### Index Shortcode Snippet

- **Title:** SERI Shortcode (or SIVI Shortcode)
- **Code Type:** PHP Snippet
- **Location:** Run Everywhere
- **Priority:** 10

### Frontend CSS Snippet

- **Title:** Blomstra Frontend Styles
- **Code Type:** CSS Snippet
- **Location:** Site Wide Header

### Frontend JS Snippet

- **Title:** Blomstra Frontend Engine
- **Code Type:** JS Snippet
- **Location:** Site Wide Footer

---

## First Build

1. Visit **Blomstra Insights -> SERI Index** (or SIVI Index)
2. Click **"Fetch (Async)"** for each pillar
3. Wait 2-5 minutes (background tasks need time to complete)
4. Refresh the page -- pillar status should show "Cached (N)"
5. Click **"Build Index from Cache"**
6. Verify composite status shows "Scored (N)"
7. Visit the REST endpoint in a browser:
   - `https://yoursite.com/wp-json/blomstra/v1/geo-economic-risk-index`
   - `https://yoursite.com/wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index`

---

## Cron Setup

### Automatic (Recommended)

The indices self-schedule on `init`:

```php
if ( ! wp_next_scheduled( SERI_DAILY_CRON_HOOK ) ) {
    wp_schedule_event( time() + 300, 'daily', SERI_DAILY_CRON_HOOK );
}
```

This requires **real WordPress cron** (not the default WP-Cron which only fires on page visits). For production:

**Option A: System cron calling WP-CLI**
```bash
# crontab -e
*/5 * * * * cd /var/www/html && wp cron event run --due-now > /dev/null 2>&1
```

**Option B: System cron calling curl**
```bash
# crontab -e
*/5 * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

**Option C: Managed host cron**
Most managed WordPress hosts (Kinsta, WP Engine, Flywheel) provide a "real cron" toggle in their control panel. Enable it.

### Manual Trigger

In the admin page, click **"Force Daily Cron Now"** to trigger a single run immediately.

---

## Troubleshooting

### "No country list available"

- Verify the shared utilities snippet is active
- Check that `blomstra_get_global_country_list()` exists
- Test the World Bank API directly: `https://api.worldbank.org/v2/country?format=json&per_page=10`

### "Central model not active"

- The index tried to use a shared batch fetcher that does not exist
- This is normal if you have not installed the reference data snippet
- The index will fall back to direct API calls automatically

### "HTTP 429" or "quota exhausted"

- **Comtrade:** You have hit the UN Comtrade rate limit. Wait 1 hour and retry.
- **EIA:** You are making too many requests. The chunking should prevent this, but if it happens, increase `SIVI_EIA_CHUNK_SIZE` or add longer `usleep()` delays.

### "Automated build failed"

- The cron safeguard triggered because the new build had <80% of the previous country count
- Check the PHP error log for API failures
- Manually refresh pillars one by one to identify which source is failing
- Once fixed, click "Build Index from Cache" to restore the composite

### Admin page shows "Never" for a pillar

- The pillar fetch completed but did not update its meta key
- This is a known issue if the fetch function does not call `update_option( {INDEX}_{PILLAR}_META_KEY, ... )`
- The data may still be cached correctly -- check the REST endpoint

### Widget not rendering

- Check browser console for JavaScript errors
- Verify `index-frontend-engine.js` is loaded (check Network tab)
- Verify the shortcode rendered a `<div data-blomstra-index="...">` element
- Test the REST endpoint directly -- if it returns 404, the backend snippet may not be active

---

## Updating an Index

1. **Deactivate** the old backend snippet in WPCode
2. **Paste** the new backend code
3. **Activate** the new snippet
4. Visit the admin page and verify version number updated
5. Run "Build Index from Cache" (no need to re-fetch pillars unless data sources changed)

**Do not** delete old option keys before verifying the new code works. If something breaks, reactivate the old snippet and the previous composite will still be available.

---

## Backup & Restore

All index data is stored in WordPress options:

```sql
-- Backup all SERI data
SELECT option_name, option_value FROM wp_options 
WHERE option_name LIKE 'seri_%' OR option_name LIKE 'blomstra_%';

-- Backup all SIVI data
SELECT option_name, option_value FROM wp_options 
WHERE option_name LIKE 'sivi_%';
```

To restore after a disaster:
```sql
-- Restore from backup
UPDATE wp_options SET option_value = '{backup_json}' WHERE option_name = 'seri_composite_index';
```

---

## Performance Notes

- **Shared utilities:** ~50KB loaded on every request. Acceptable.
- **Index backend:** ~200KB per index. Only loads admin UI for admins.
- **Frontend:** CSS + JS ~30KB combined. Loaded site-wide but cached.
- **REST API:** Response is ~500KB-1MB JSON. Enable WordPress object caching (Redis/Memcached) if serving high traffic.
- **Cron tasks:** Energy fetch (EIA) takes ~3 minutes. HHI fetch (Comtrade) takes ~5-10 minutes. Maritime fetch takes ~10 seconds. Schedule accordingly.
