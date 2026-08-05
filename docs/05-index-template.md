# Index Template Specification

This checklist turns index creation from improvisation into a repeatable factory process. CIV/CII is the reference implementation.

---

## Phase 1: Backend

### Constants & Configuration

- [ ] Define `INDEX_VERSION` constant (SemVer)
- [ ] Define API endpoint constants (base URLs, cache keys)
- [ ] Define chunk sizes (match Reference Data or justify divergence)
- [ ] Define pillar weights (must sum to 1.0)
- [ ] Define `MIN_PILLARS_REQUIRED` (minimum for partial coverage)
- [ ] Define band thresholds and labels

### Pillar Refreshers

For each pillar, implement:

- [ ] `index_refresh_{pillar}($source = 'auto')` dispatcher
  - [ ] `central` mode — call Reference Data, fail explicitly if empty
  - [ ] `central_cached` mode — read stored cache, zero API calls
  - [ ] `api` mode — complete fallback engine with identical chunking/retry
  - [ ] `auto` mode — central first, fallback silently
- [ ] Store results in option: `{index}_{pillar}_pillar`
- [ ] Per-country shape: `{value, source, note, last_updated}`
- [ ] Set transient cache per country (optional, for frontend speed)

### Composite Builder

- [ ] `index_build_composite($meta_source = 'manual')`
- [ ] Extract raw values from all pillar options
- [ ] Compute percentile ranks: `index_compute_percentile_ranks()`
- [ ] Apply index-specific normalization (e.g. invert maritime)
- [ ] Weighted composite calculation
- [ ] Coverage classification (Full / Partial / Excluded)
- [ ] Rank assignment:
  - [ ] Full Index → definitive ordinal rank
  - [ ] Partial Index → injection simulation (0, 10, 50, 90, 100)
- [ ] Build `rank_display` object (definitive or projected)
- [ ] Populate `excluded_detail` with reasons
- [ ] Add `_meta` block
- [ ] Call `blomstra_index_snapshot_save('{slug}', $countries)`
- [ ] Persist to option: `{index}_composite_index`

### REST Endpoint

- [ ] Register route: `/wp-json/blomstra/v1/{index-key}`
- [ ] Return standard composite schema (see [03-api-contract.md](03-api-contract.md))
- [ ] Handle "not built yet" → 404

### Admin Page

- [ ] Register under `blomstra-insights-tools` menu
- [ ] Status dashboard (cached/count per pillar)
- [ ] Pipeline health (pillar ages, composite age, lock status)
- [ ] Per-pillar refresh buttons (Central / API)
- [ ] "Build Composite" button
- [ ] "Refresh All & Build" button
- [ ] Flush buttons (per pillar + all)
- [ ] Diagnostics panel (call logs, summaries)
- [ ] Composite preview table (top 20 Full + Partial)

### Cron

- [ ] Register daily cron hook
- [ ] Cron callback uses `central_cached` only
- [ ] Freshness gate (skip if pillars stale)
- [ ] Build lock (5-min TTL, self-healing)
- [ ] Write `cii_last_wpcron_fired` timestamp (separate from test button)
- [ ] Admin notice if wp-cron stale (>30h)

---

## Phase 2: Frontend

### Shortcode PHP

- [ ] Register `[{index}_index]` shortcode
- [ ] Output `.biw` container with all `data-biw-*` attributes
- [ ] Define `$pillars` array matching backend output keys
- [ ] Write methodology HTML string
- [ ] Set appropriate `data-biw-band-thresholds`

### Verification

- [ ] Shortcode renders on page
- [ ] Widget boots (check `data-biw-initialized` attribute)
- [ ] Data loads (check network tab for 3 parallel requests)
- [ ] Table renders with correct column count
- [ ] Sort by each pillar works
- [ ] Filter by band works
- [ ] Search works
- [ ] Watchlist add/remove works
- [ ] Modal opens with correct data
- [ ] Print stylesheet works
- [ ] Mobile responsive

---

## Phase 3: Integration

- [ ] Both snippets active simultaneously on same page
- [ ] No JavaScript errors in console
- [ ] No CSS conflicts with theme
- [ ] Rank is static when filtering
- [ ] Delta arrows appear after 2+ monthly snapshots
- [ ] Admin page shows health status correctly
- [ ] Daily cron fires and builds successfully

---

## Example: New 2-Pillar Index

```php
// Backend
if (!defined('MINERAL_WEIGHT_EXPORT')) define('MINERAL_WEIGHT_EXPORT', 0.5);
if (!defined('MINERAL_WEIGHT_RENT'))   define('MINERAL_WEIGHT_RENT', 0.5);
if (!defined('MINERAL_MIN_PILLARS'))   define('MINERAL_MIN_PILLARS', 1);

// Frontend shortcode
$pillars = [
    ['key' => 'export_share_percentile', 'raw_key' => 'export_share_raw', 'label' => 'Export Share', 'color' => '#60a5fa'],
    ['key' => 'mineral_rent_percentile', 'raw_key' => 'mineral_rent_raw', 'label' => 'Mineral Rent', 'color' => '#f87171'],
];
```

The engine handles this identically to a 3-pillar or 4-pillar index. No JavaScript changes required.
