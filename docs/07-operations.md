# Operations & Runbook
> **Tier:** T4 — Operational
> **UDM Version:** UDM-1.0.0
> **BMS Conformance:** BMS-1.1.0
> **Applies to:** All L1 fetchers, L3 index backends, admin dashboards
> **Last updated:** 2026-08-26
> **SSOT For:** Health monitoring, troubleshooting procedures, cron schedules, deviation registry, emergency procedures
> **Depends on:** `01-architecture.md` (T1 state machine), `03-reference-data-impl.md` (T3 cron handlers, safeguards)

---

## 1. Health Monitoring

### 1.1 Health State Taxonomy

Every pillar and index exists in exactly one of these states:

| State | Icon | Meaning | Action |
|---|---|---|---|
| `success` | 🟢 | Last run completed, data fresh | None |
| `partial` | 🟡 | Last run completed, some data missing | Monitor; retry if persistent |
| `running` | 🔵 | Currently executing | Wait |
| `stuck` | 🔴 | Lock exists but run timed out | Clear lock, retry |
| `error` | 🔴 | Permanent failure or validation failed | Investigate logs |
| `retryable` | 🟠 | Transient failure, will retry automatically | Wait for next cron |
| `stale` | 🟡 | Data exists but exceeds freshness threshold | Refresh recommended |
| `never-run` | ⚪ | No data, no pointer, no lock | Initialize |

### 1.2 Health Status Table (Admin Dashboard)

| Column | Description |
|---|---|
| **Pillar** | Dataset name (Maritime, EIA, HHI, WB Indicators, IMF, SERI, SIVI) |
| **Status** | One of the 8 states above |
| **Coverage** | Countries cached / expected (with percentage) |
| **State Breakdown** | Per-state counts (e.g., "150 succeeded, 12 no_data, 3 quota") |
| **Last Successful** | Timestamp of last successful completion |
| **Next Scheduled** | When the next cron run is scheduled |
| **Action Suggestion** | Auto-generated recommendation based on status |

### 1.3 Action Suggestion Logic

```
if (cache_empty) → "⚠️ Cache is empty – use Refresh below"
elseif (pointer_incomplete && status in [success, partial]) → "⏳ Refresh in progress"
elseif (status == partial && !pointer_incomplete && cache > 0) → "⚠️ Some data missing – retry or wait"
elseif (status == success && !stale) → "✅ Up to date"
elseif (stuck && lock_exists) → "🔒 Stuck – click Refresh to clear lock"
elseif (never_run) → "⏳ Never run – use Refresh below"
elseif (stale) → "⏳ Stale – refresh recommended"
elseif (error || retryable) → "⚠️ Error – check logs, use Refresh"
elseif (running) → "🔄 Running – wait for completion"
```

---

## 2. Cron Scheduling

### 2.1 Collection Frequency

| Pillar | Frequency | Hook | Lock Key | TTL |
|---|---|---|---|---|
| Countries | Weekly | `blomstra_cron_countries_async_event` | `blomstra_countries_async_in_progress` | 10 min |
| Reporters | Weekly | `blomstra_cron_reporters_async_event` | `blomstra_reporters_async_in_progress` | 10 min |
| Maritime | Weekly | `blomstra_cron_maritime_weekly_event` | `blomstra_maritime_weekly_in_progress` | 10 min |
| HHI | Weekly | `blomstra_cron_hhi_weekly_event` | `blomstra_hhi_refresh_in_progress` | 30 min |
| EIA | Weekly | `blomstra_cron_eia_weekly_event` | `blomstra_eia_refresh_in_progress` | 30 min |
| WB Indicators | Weekly | `blomstra_cron_wb_indicators_weekly_event` | `blomstra_wb_refresh_in_progress` | 30 min |
| IMF | Weekly | `blomstra_cron_imf_weekly_event` | `blomstra_imf_weekly_in_progress` | 10 min |

### 2.2 Staleness Thresholds

| Pillar | Threshold | Action if Stale |
|---|---|---|
| WB Indicators | 7 days | Fetch from API, cache in transient |
| EIA | 7 days | Fetch from API, cache in option |
| HHI | 7 days | Fetch from API, cache in option |
| Maritime | 7 days | Fetch from API, cache in transient |
| IMF | 7 days | Fetch from API, cache in transient |
| Countries | 30 days | Fetch from API, cache in transient |
| Reporters | 30 days | Fetch from API, cache in transient |
| Landlocked | 6 months | Manual verification required |

### 2.3 Manual Refresh Procedures

All pillars can be manually refreshed via:

1. **Admin Data Health Dashboard** → Click "Refresh" on any pillar.
2. **API Diagnostic Sandbox** → Test single targets without triggering batch execution.
3. **Index Admin** → Click "Refresh All Reference Data" (triggers all pillars).

---

## 3. Troubleshooting

### 3.1 Decision Tree: "Should I Refresh?"

```
Is cache empty?
  ├─ YES → Refresh
  └─ NO → Is pointer incomplete?
      ├─ YES → Refresh (resume)
      └─ NO → Is status == partial?
          ├─ YES → Refresh (retry missing)
          └─ NO → Is data stale?
              ├─ YES → Refresh
              └─ NO → Is status == error?
                  ├─ YES → Check logs, then Refresh
                  └─ NO → Data is healthy
```

### 3.2 Emergency Procedures

| Situation | Command | Effect |
|---|---|---|
| Stuck lock | Flush pillar cache | Deletes lock transient + pointer |
| Corrupt staging | Flush pillar cache | Deletes staging option |
| Need clean slate | Emergency Flush All | Deletes ALL L1 caches, pointers, logs |
| Index build failed | Rebuild from cache | Triggers `build_composite(manual)` without re-fetching L1 |
| Suspect API outage | API Diagnostic Sandbox | Tests single target without batch execution |

### 3.3 Common Failures

| Symptom | Likely Cause | Resolution |
|---|---|---|
| HHI stuck at 40% | Comtrade quota exhausted | Wait 1 hour, check quota status, retry |
| EIA missing one fuel | Permanent failure (403/404) | Check API key, clear failure state, retry |
| WB indicator empty | `mrnev` returned no data | System auto-fallback to 10-year range |
| Build count dropped 50% | L1 cache partially empty | Refresh L1 first, then rebuild index |
| Frontend shows "N/A*" for all ranks | All countries partial coverage | Check L1 cache health, at least 2 pillars must be present |
| Watchlist lost on refresh | localStorage cleared | Not a bug — localStorage is client-side only |

---

## 4. Data Collection Methods

### 4.1 Source Matrix

| Source | Endpoint | Chunk Size | Lookback | Key Safeguard |
|---|---|---|---|---|
| WB WGI | `api.worldbank.org` | N/A | 1 year | `source=3` parameter |
| WB WDI | `api.worldbank.org` | N/A | 10 years | Hybrid `mrnev` → range fallback |
| IMF WEO | `datamapper.api` | N/A | 5 years (forecast) | Vintage-aware (April 2026) |
| UN Comtrade (HHI) | `comtradeapi.un.org` | 50 reporters | 4 years | Smart pagination, rate-limit parsing |
| EIA | `api.eia.gov` | 50 countries | 1 year | Conditional updates (preserve other activity) |
| WB LSCI | `api.worldbank.org` | N/A | 20 years | Retry on empty response |

### 4.2 Data Quality Matrix

| Source | Latency | Coverage | Known Issues | Mitigation |
|---|---|---|---|---|
| WB WGI | ~1 year | ~200 countries | Source=3 required for WGI | Explicit `source=3` parameter |
| WB WDI | ~1 year | ~200 countries | `mrnev` may return empty | Hybrid `mrnev` → range fallback |
| IMF WEO | 2×/year (Apr/Oct) | ~190 countries | Forecast vs actual mixed | Vintage awareness, actual preferred |
| UN Comtrade | ~1–2 years | ~200 countries | Quota limits, missing reporters | Chunked batch, smart pagination, lookback |
| EIA | ~1 year | ~200 countries | Rate limits, per-fuel failures | Chunked batch, conditional updates, retry |
| WB LSCI | ~1 year | ~150 countries | Landlocked structural zeros | Hardcoded landlocked list, zero assignment |

---

## 5. Deviation Registry

Every known deviation from BMS-1.1.0 must be logged with this schema:

```markdown
### Deviation ID: DEV-{NNNN}
**Standard:** BMS-1.1.0
**Clause:** [which conformance predicate is affected]
**Description:** [what was done differently]
**Rationale:** [why]
**Risk:** [Low / Medium / High]
**Mitigation:** [how the risk is addressed]
**Date introduced:** YYYY-MM-DD
**Review date:** YYYY-MM-DD
```

### 5.1 Registered Deviations

#### DEV-0001: IMF WEO Vintage Handling
**Clause:** Predicate 2 (State machine for all async operations)
**Description:** IMF data does not use a formal state machine pointer. All 6 indicators are fetched in a single cron run.
**Rationale:** IMF API is fast and reliable enough for single-run completion. The 10-minute lock transient prevents duplicates.
**Risk:** Low. IMF API rarely times out.
**Mitigation:** 10-minute lock transient prevents duplicates.
**Date introduced:** 2026-08-26
**Review date:** 2026-10-26

#### DEV-0002: EIA Fuel Skipping
**Clause:** Predicate 3 (Staging → atomic promotion)
**Description:** If a fuel is marked as permanent failure, it is skipped entirely in future runs. There is no automatic retry after a long delay.
**Rationale:** Permanent failures (403/404) indicate API key or endpoint issues that require manual intervention.
**Risk:** Medium. A fuel could be permanently skipped due to a transient auth issue.
**Mitigation:** Admin can clear the failure state via "Emergency Flush All" or by deleting the EIA pointer manually.
**Date introduced:** 2026-08-26
**Review date:** 2026-09-26

#### DEV-0003: HHI Staging Coverage Threshold
**Clause:** Predicate 3 (Staging → atomic promotion)
**Description:** The 80% threshold is based on `total_fetchable` (countries with reporter codes), not the full country list.
**Rationale:** Countries without reporter codes (e.g., disputed territories) are not fetchable and should not count against coverage.
**Risk:** Low. The threshold is conservative even with this adjustment.
**Mitigation:** None required.
**Date introduced:** 2026-08-26
**Review date:** 2026-10-26

#### DEV-0004: WB Indicator Staging Uses Transients
**Clause:** Predicate 3 (Staging → atomic promotion)
**Description:** WB indicators use transients (`blomstra_wb_indicator_{md5}`) instead of options for staging/production.
**Rationale:** WB data is high-volume (13 indicators × ~200 countries) and changes frequently. Transients auto-expire, preventing database bloat.
**Risk:** Medium. Transients may be evicted by object cache plugins.
**Mitigation:** Staging transient warm-cache pattern. If main transient expired but staging exists, staging is promoted immediately.
**Date introduced:** 2026-08-26
**Review date:** 2026-09-26

---

## 6. Changelog

### 2026-08-26 — v2.7.28 Reference Data / v1.1.3 Utilities / BMS-1.1.0 / UDM-1.0.0

**Added:**
- State machine architecture with resumable pointers for HHI, EIA, WB indicators
- Staging → atomic promotion with coverage thresholds
- Per-country state classification (9 terminal states for HHI)
- Cron lock transients for all pillars (duplicate prevention)
- Admin Data Health Dashboard with real-time status, action suggestions, and granular cache control
- API Diagnostic Sandbox for single-target testing
- Snapshot history database (`wp_blomstra_index_history`)
- Statistical research layer: Spearman correlation, Cronbach's alpha, bootstrap weight-sensitivity intervals, benchmark correlation
- Partial-rank composite projection (OECD/JRC injection points)
- Scenario-safe composite builder (custom weights cannot overwrite live data)
- Cron auto-rollback (preserves old composite if build drops below 80% country count)
- Safe numeric/string extraction utilities (replaces dangerous `empty()` patterns)
- Winsorized percentile computation with tie handling
- Fallback merging with full provenance tracking
- Data quality flags and pillar quality scores
- Unified Documentation Model (UDM-1.0.0) — 4-tier taxonomy, SSOT rules, cross-layer contracts

**Changed:**
- HHI engine: complete rewrite with chunked batch, smart pagination, lookback, per-country state machine, surgical fallback
- EIA engine: complete rewrite with fuel × activity pointer, conditional updates, retry/perm-fail/quota states
- WB indicators: added pointer-based batch processing (3 indicators per cron run)
- Admin UI: replaced basic refresh buttons with Data Health Dashboard + API Sandbox
- SERI: migrated from GERI v3.x architecture
- SIVI: migrated from CII v1.0.0 architecture
- Naming: standardized to SERI / SIVI / GPRI
- Documentation: restructured from 9 scattered files into 7 coherent tiered documents

**Fixed:**
- Null `$total_pages` bug in WB country list (RD-04)
- Expired reporter entries not being skipped (RD-16)
- Maritime empty response not triggering retry
- HHI missing `partnerCode=0` data not being detected
- EIA memory leak from processing all fuels in one run
- Cron duplicate execution without lock transients
- Direct production cache overwrite (now uses staging)
- `empty()` false positives on `0.0` values
- Stale cache not being used as fallback on API failure
