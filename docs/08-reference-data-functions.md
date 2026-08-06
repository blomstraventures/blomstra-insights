# Reference Data — Function Reference

This documents every function in `blomstra-reference-data.php` (the
"Utility - Global Reference data" WPCode snippet) as it actually exists in
the source, organized by section. If you're building a new index and
wondering "does something already do this," check here before writing new
collection code — see [01-architecture.md](./01-architecture.md) for why
that matters.

**Load order matters:** this snippet must run before any index tool that
depends on it (WPCode execution order / priority).

---

## Landlocked countries

```php
blomstra_is_landlocked( $iso3 )  // bool
```
Checks against `BLOMSTRA_LANDLOCKED_ISO3`, a hardcoded constant (the
UN-OHRLLS LLDC list, 44 countries). Static list, not fetched from
anywhere — this is the source of the Maritime pillar's "structural zero"
handling (see [09-methodology-deepdive.md](./09-methodology-deepdive.md)).

Known limitation: the drift check (in the admin page) only catches one
direction — a static-list code no longer present in the live country
list. There's no live source to verify the reverse (a country that should
be added to the list).

## Global country list

```php
blomstra_get_global_country_list( $force = false )  // ISO3 => name
```
Source: World Bank Countries API (`api.worldbank.org/v2/country`),
paginated (300/page) until exhausted. Filters out non-country aggregates
by requiring `region.id !== 'NA'` (World Bank's own convention for
"this isn't a real country, it's a regional aggregate"). Cached as a
transient for `DAY_IN_SECONDS`. This is the backing data for the
`country-names` REST route (below) and the default country scope for
every pillar's collection when no explicit ISO3 list is passed.

## Comtrade reporter-code map

```php
blomstra_get_comtrade_reporter_map( $force = false )  // ISO3 => numeric reporter code
```
Source: Comtrade's own reference file
(`comtradeapi.un.org/files/v1/app/reference/Reporters.json`). Cached for
`WEEK_IN_SECONDS`.

**This function contains the expired-reporter-entry fix** — the case
study in [01-architecture.md](./01-architecture.md). The critical line:

```php
if ( isset( $map[ $iso3 ] ) && ! empty( $reporter['entryExpiredDate'] ) ) {
    continue;
}
```

An entry with `entryExpiredDate` set can never overwrite an
already-stored code, regardless of the order Comtrade lists entries in.
Any future code touching Comtrade reporter codes should call this
function rather than re-deriving the map, specifically so this bug class
can't reappear in a second implementation.

Debug info from the last fetch is stored in
`blomstra_comtrade_reporters_debug` (HTTP code, body snippet, parsed
count) — check this option directly if reporter lookups seem wrong.

## Maritime (World Bank LSCI, raw only)

```php
blomstra_get_maritime_raw( $force = false, $attempt = 1 )   // ISO3 => {value, year}
blomstra_get_maritime_value( $iso3 )                         // {value, is_landlocked, year}
```
Source: World Bank indicator `IS.SHP.GCNW.XQ`, last 20 years, one bulk
call (`per_page=20000`) rather than paginated/chunked — this is the
pillar most exposed to a single point of failure, which is why it has
**one automatic retry on network-level failure** (`$attempt < 2`, 3
second sleep) built directly into the function. Picks the most recent
year per country. Cached `WEEK_IN_SECONDS`.

`blomstra_get_maritime_value()` is the one to call from an index — it
already folds in the landlocked structural-zero check via
`blomstra_is_landlocked()`, so callers don't need to check landlocked
status separately.

Debug info in `blomstra_maritime_fetch_debug`.

## HHI (UN Comtrade, full collection engine)

```php
blomstra_refresh_comtrade_hhi_data( $year = null, $iso3_list = null, $force = false )  // triggers a full run
blomstra_get_comtrade_hhi_data()                                                       // cached results, ISO3 => {value, scale, ...}
blomstra_get_country_hhi_value( $iso3 )                                                // single country's cached result
```
This is the most complex piece in the file — worth understanding in
detail if you're building an index that also needs Comtrade data.

**Shape of a run:**
1. Resolve reporter codes for the requested country list via
   `blomstra_get_comtrade_reporter_map()`. Countries with no reporter
   code are marked `source: 'no reporter code'` immediately, not retried.
2. Chunk remaining countries into batches of `BLOMSTRA_HHI_CHUNK_SIZE`
   (50), fetch each chunk via
   `blomstra_comtrade_fetch_partner_imports_batch()`.
3. **Checkpoint after every chunk** — merges partial results into the
   `blomstra_comtrade_hhi_data` option immediately, so a crash mid-run
   doesn't lose everything collected so far. This exists because an
   earlier two-tier filtered/unfiltered approach only saved once at the
   very end and lost entire runs to mid-run failures.
4. If a chunk comes back quota-exhausted (`BLOMSTRA_COMTRADE_QUOTA_EXHAUSTED`
   sentinel), the whole run stops taking new API calls but still finishes
   writing out whatever was already collected — quota exhaustion doesn't
   corrupt or discard prior progress.
5. **Lookback window** (`BLOMSTRA_HHI_LOOKBACK`): if a country has no
   data for the requested year, the function tries earlier years before
   giving up, since trade data reporting lags for some countries.
6. Anything still unresolved after the lookback window is marked
   `'no data in lookback window'` (genuinely no data) vs. `'skipped —
   quota exhausted this run'` (would very likely have data, just didn't
   get to try) — these are deliberately different statuses so you can
   tell "this country really has no data" from "this country just needs
   a re-run."

A full run summary (countries in scope, succeeded/no-data/quota-skipped
counts, failed chunks, timestamps) is written to
`blomstra_hhi_refresh_summary` throughout the run, not just at the end —
this is what the admin page's live progress view reads from.

## EIA (Energy, raw per-fuel data only)

```php
blomstra_refresh_eia_raw_data( $iso3_list = null, $force = false )  // triggers a full run
blomstra_get_eia_raw_data()                                          // {consumption: {...}, production: {...}}
blomstra_get_eia_country_totals( $iso3 )                             // {consumption_qbtu, production_qbtu} summed across all fuels
```
**Deliberately scoped narrower than HHI.** This only centralizes *raw*
per-fuel-per-country consumption/production figures. The multi-fuel
weighting formula that turns those raw figures into an "energy
dependency" score is CII's own methodology choice
(`cii_eia_aggregate_energy_dependency()`, lives in CII, not here) — a
future index might reasonably want a *different* weighting of the same
raw fuel data, so the formula itself was deliberately not centralized.

Iterates every fuel in `BLOMSTRA_EIA_FUEL_PRODUCT_IDS`, and for each
fuel, both `consumption` and `production` activities, each chunked at
`BLOMSTRA_EIA_CHUNK_SIZE`. Checkpoints **after every fuel** (not just at
the end) into `blomstra_eia_raw_data`. Production values of exactly zero
are still recorded (`status: 'confirmed_zero'`) rather than treated as
missing — a genuine zero and a missing value are different things and
this preserves that distinction. `blomstra_eia_pick_latest_per_country()`
picks the most recent year per country per batch.

`blomstra_get_eia_country_totals()` is a convenience sum across every
fuel for one country — useful for diagnostics, not itself the
methodology.

## API key health check

```php
blomstra_check_api_keys_status()  // {comtrade: bool, eia: bool}
```
Just checks whether `COMTRADE_PRIMARY_KEY` / `EIA_API_KEY` constants are
defined and non-empty. Cheap sanity check surfaced on the admin page —
worth checking first if a pillar mysteriously stops returning data.

## Cron scheduling

Three independent **weekly** crons, staggered across different days so
they don't compete for quota simultaneously:

| Cron event | Day/time (UTC) | Calls |
|---|---|---|
| `blomstra_cron_maritime_weekly_event` | Monday 02:00 | `blomstra_get_maritime_raw( true )` |
| `blomstra_cron_eia_weekly_event` | Tuesday 02:00 | `blomstra_refresh_eia_raw_data( null, true )` |
| `blomstra_cron_hhi_weekly_event` | Wednesday 02:00 | `blomstra_refresh_comtrade_hhi_data( null, null, true )` |

Each writes to `blomstra_cron_status` via `blomstra_update_cron_status()`
(`running` → `success`/`error`, with a message and count) — this is
separate from CII's own daily composite-rebuild cron, which reads
whatever these crons have already collected rather than fetching
anything itself.

**Note:** these are the *raw collection* crons living in Reference Data.
CII's own cron (in the CII snippet, not here) triggers the actual
composite build/scoring on its own schedule, reading from whatever these
crons have most recently deposited.

## Multi-year snapshot history *(added this project, Phase 0)*

```php
blomstra_index_snapshot_save( $index_slug, $countries )        // upserts, returns rows written
blomstra_index_snapshot_get_history( $index_slug, $iso3 = null ) // ISO3 => [{period, composite_score, rank, coverage_type, pillars}, ...]
```
Backed by a dedicated table, `wp_blomstra_index_history` (not
`wp_options` — this is genuinely tabular, growing data, not
configuration). Table auto-creates/updates via `dbDelta()` on
`admin_init`, version-gated so it's cheap to check repeatedly.

**Upserts at most once per calendar month** per `(index_slug, iso3)` —
the unique key is `(index_slug, iso3, snapshot_period)` where
`snapshot_period` is `'Y-m'`. Call this once, right after any index's
composite build succeeds, passing the same per-country array about to be
saved as the live composite. Repeated rebuilds within the same month just
update that month's row (verified live: rebuilding CII twice in one day
kept the country count fixed while `recorded_at` advanced — confirms
update-in-place, not duplication). A new month starts a fresh row
automatically, no cron or scheduling needed for this part — it just rides
on whatever already triggers an index's own rebuild.

Everything in the passed row except `composite_score`, `rank`, and
`coverage_type` gets JSON-encoded into `pillars_json` as-is — including
the full `rank_display` object — so historical entries carry enough
detail for the frontend's rank-delta feature to compute an
apples-to-apples comparison even for countries that were Partial Index in
one period and Full Index in another.

## REST routes

| Route | Returns | Added |
|---|---|---|
| `GET /wp-json/blomstra/v1/country-names` | Full ISO3 → name map (same as `blomstra_get_global_country_list()`) | This project, Phase 0 |
| `GET /wp-json/blomstra/v1/index-history/{slug}?iso3=XXX` (iso3 optional) | `blomstra_index_snapshot_get_history()` output | This project, Phase 0 |

Both are `permission_callback => '__return_true'` — public, read-only,
no auth. This is intentional for now (frontend widgets need to read these
unauthenticated) but worth revisiting once the paid-API tier of the
business model exists — see the monetization phase in
[00-index.md](./00-index.md)'s parent roadmap.

## Admin UI

Registers a top-level **Blomstra Insights Tools** WordPress admin menu
(`blomstra_ref_register_page()`, priority 5 — deliberately earlier than
CII's own submenu registration at priority 20, so Reference Data's page
is the default landing view). Handles refresh/flush actions via
`blomstra_ref_handle_early_actions()`, hooked to `admin_init` specifically
(not run inline in the page-render callback) — this avoids the
redirect-after-action blank-page bug that CII hit early on, where
`wp_safe_redirect()` was being called after WordPress had already output
admin header HTML.

---

## What to read next

- The pipeline stage-by-stage, including where these functions fit → [02-data-flow.md](./02-data-flow.md)
- How a new pillar type should be added → [05-index-template.md](./05-index-template.md)
- What CII actually does with this raw data (percentile ranks, Full vs.
  Partial Index) → [09-methodology-deepdive.md](./09-methodology-deepdive.md)
- Cron scheduling and troubleshooting → [06-deployment.md](./06-deployment.md)
