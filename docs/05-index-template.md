# Index Template Specification

This checklist turns index creation from improvisation into a repeatable factory process. CII (destined to be renamed CIV — see the [README](../README.md)) is the reference implementation.

---

## The 8 phases, and why this order specifically

Before the checklist, the reasoning — each phase exists to prevent a specific failure mode actually hit while building CII, not as an arbitrary process:

1. **Contract-first.** Define the JSON schema (start from [03-api-contract.md](03-api-contract.md)) before writing any collection code. Deviate only with a specific, stated reason, and update that document if you do.
2. **Single-item-before-batch.** Prove a fetch works for exactly one country, one call, before writing pagination or chunking — don't debug batching and data-correctness at the same time.
3. **Logging built in from day one.** A call-log option exists from the first collection function you write, not retrofitted after something breaks silently in production.
4. **Incremental checkpointing.** Save partial progress after every chunk, never only at the end of a full run — this is the actual root cause behind real bugs hit twice historically (an early two-tier HHI approach losing entire runs to a mid-run crash; EIA having the identical single-save vulnerability before it got the same fix).
5. **Test hard cases before trusting the aggregate.** Before a 200-country batch, hand-verify 2-3 deliberately tricky countries — a landlocked one if touching Maritime, a country with a known reporter-code discontinuity if touching Comtrade (see [01-architecture.md](01-architecture.md)'s case study), and one that should genuinely have no data.
6. **One pillar fully done before starting the next.** Not because pillars are inherently sequential — because debugging two simultaneously-incomplete pillars makes it ambiguous which one caused an odd composite result.
7. **Composite/scoring work comes last.** Every pillar collecting real data — centralized, with its own fallback — before writing any weighting, percentile, or rank logic.
8. **Diagnostics and maintenance discipline.** Every collection function gets an admin-visible debug option and call log from the start.

**Should this index's pillar collection be centralized from day one?** Yes, by default, for anything that might plausibly be reused — almost anything hitting Comtrade, EIA, or World Bank again. Build the raw collection directly in Reference Data as the primary path, with your index owning only a thin dispatcher plus complete fallback, from the start — rather than building inside the index first and migrating later. CII's pillar-by-pillar migration was worthwhile the first time, precisely so it wouldn't need repeating.

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
  - [ ] Partial Index → injection simulation (0, 10, 50, 90, 100) — see [09-methodology-deepdive.md](09-methodology-deepdive.md) for why, and its note on this only working cleanly for a single missing pillar
- [ ] Build `rank_display` object (definitive or projected) — on every row, Full or Partial alike
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
- [ ] Write a "last real wp-cron fire" timestamp, separate from the manual test button's own status write (see [01-architecture.md](01-architecture.md)'s build-reliability section for why these must stay separate)
- [ ] Admin notice if wp-cron stale (>30h)

---

## Phase 2: Frontend

### Shortcode PHP

- [ ] Register `[{index}_index]` shortcode
- [ ] Output `.biw` container with all `data-biw-*` attributes
- [ ] Define `$pillars` array matching backend output keys
- [ ] Write methodology HTML string
- [ ] Set appropriate `data-biw-band-thresholds` — suited to *this* index's own score distribution, not copied from CII's `25,50,75`

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
- [ ] Delta arrows appear after 2+ monthly snapshots (show `NEW` before that — correct, not a bug)
- [ ] Admin page shows health status correctly
- [ ] Daily cron fires and builds successfully

---

## Phase 4: Document and commit

- [ ] Update [01-architecture.md](01-architecture.md)'s pillar migration table if you centralized a new pillar type
- [ ] Update [03-api-contract.md](03-api-contract.md) if you had a principled reason to deviate from the contract
- [ ] Update this document if you hit a step that was missing, wrong, or needed more detail
- [ ] Commit with a message describing what this index added, push

This is a repeating loop, not a one-time task: **document → commit → build the next index → document its additions → commit → repeat.**

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
