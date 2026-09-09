# OPERATIONS.md – Blomstra Insights Operations Runbook

Document version: 1.0.0\
Status: CANONICAL\
Applies to: All production environments running Blomstra Insights\
Last verified against commit: (to be filled)\
Source files: `global-reference-data.php` (cron handlers), `blomstra-index-alerts.php`, `sivi-backend.php` (auto-refresh), `blomstra-index-utilities.php` (stale checks)\
Effective date: 2026-09-09

***


## Document Control

| Field                   | Value                                                                                                                                                      |
| :---------------------- | :--------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Document                | `OPERATIONS.md` v1.0.0                                                                                                                                     |
| Verified against commit | (to be filled)                                                                                                                                             |
| Source files            | `global-reference-data.php` (cron handlers), `blomstra-index-alerts.php`, `sivi-backend.php` (auto-refresh), `blomstra-index-utilities.php` (stale checks) |
| Method                  | Code-first reconstruction (Stage 2) — cross-references rather than repeats acquisition/build detail already documented in `DATA.md` and `SIVI.md`.         |
| Status                  | Complete for current code. Items in §11 are open questions, not implementation gaps.                                                                       |

Statements below are \[N] normative (a rule; violating it in future code is a bug) or \[D] descriptive (today's implementation; may change without violating a rule).

***


## Table of Contents

1. Overview

2. Deployment Prerequisites

3. Cron Schedule

4. Lock Management

5. Health States & Monitoring

6. Action Suggestions

7. Alert System Operations

8. Recovery Scenarios

9. WP-Cron Caveat

10. Backup & Restore

11. Open Questions

12. Corrections to Prior Documentation

***


## 1. Overview

\[D] The Blomstra Insights platform runs as a WordPress plugin with background cron jobs for data acquisition, index building, alert delivery, and cleanup. This document describes how to monitor, maintain, and troubleshoot the system in production.

\[N] All long-running operations use lock transients to prevent duplicate execution. All failures are logged to `error_log()` and to the `blomstra_cron_status` option for health monitoring.

\[N] Full functionality requires two external credentials (see §2). Without them, the system degrades gracefully — missing pillars mean partial coverage, not total failure — per SIVI's `SIVI_MIN_PILLARS_REQUIRED = 2` floor.

***


## 2. Deployment Prerequisites

\[D] Two external credentials required for full functionality:

| Credential             | Source                       | Where to Set                                     | Degradation Without It                                                                |
| :--------------------- | :--------------------------- | :----------------------------------------------- | :------------------------------------------------------------------------------------ |
| `COMTRADE_PRIMARY_KEY` | UN Comtrade subscription key | `wp-config.php` constant or admin credentials UI | HHI pillar never populates; SIVI degrades to at most 2-of-3 pillar (partial) coverage |
| `EIA_API_KEY`          | US EIA API key               | `wp-config.php` constant or admin credentials UI | Energy pillar never populates; same partial-coverage degradation                      |

\[D] Credential precedence: `blomstra_api_credentials` option (admin-editable) takes precedence over PHP constants (`COMTRADE_PRIMARY_KEY`, `EIA_API_KEY`). Constants act as a deploy-time default; the option acts as an admin-editable override. World Bank and IMF sources need no credential.

\[D] Two database tables are auto-created on `admin_init` (`blomstra_index_history`, `blomstra_historical_data`, `blomstra_cache_jobs` — via `dbDelta()` and version-gated re-installs) and one on first alert-system load (`blomstra_alerts`, plus an idempotent `log_type` column migration).

\[N] No manual migration step is required at deploy time — visiting any wp-admin page after deploying new code is sufficient to trigger any pending schema change, since installation hooks fire on `admin_init`.

***


## 3. Cron Schedule

\[D] Full schedule from `DATA.md` §8, repeated here for operational convenience:

| Day (UTC 02:00)              | Job                                                                        | Notes                                                     |
| :--------------------------- | :------------------------------------------------------------------------- | :-------------------------------------------------------- |
| Monday                       | Maritime refresh (`blomstra_cron_maritime_weekly_event`)                   | Simple, single-pass                                       |
| Tuesday                      | EIA refresh (`blomstra_cron_eia_weekly_event`)                             | Chunked, may self-reschedule sub-ticks same day           |
| Wednesday                    | HHI (Comtrade) refresh (`blomstra_cron_hhi_weekly_event`)                  | Chunked, may self-reschedule sub-ticks same day           |
| Thursday                     | World Bank indicators refresh (`blomstra_cron_wb_indicators_weekly_event`) | Per-indicator pointer sequence                            |
| Friday                       | IMF WEO refresh (`blomstra_cron_imf_weekly_event`)                         | Simple, single-pass                                       |
| Daily 03:00 UTC              | SIVI auto-refresh (`sivi_auto_refresh_cron`)                               | Rebuilds composite from whatever L1 caches currently hold |
| Daily (+1h after activation) | Alert cleanup (`blomstra_alert_cleanup_daily`)                             | Deletes old alerts, trims per index                       |

\[D] All weekly hooks use the custom cron schedule `'weekly'` (interval = `WEEK_IN_SECONDS`), registered via `blomstra_register_weekly_cron_schedule()`.

\[N] SIVI additionally rebuilds on-demand within \~60 seconds of any of the five weekly source crons completing (debounced via `sivi_auto_refresh_queued` transient), not just at its fixed 03:00 daily slot — so a Tuesday EIA refresh completing at, say, 02:47 UTC triggers a SIVI rebuild almost immediately, and the 03:00 daily rebuild is really a safety-net catch-all rather than the primary trigger in normal operation.

\[D] EIA and HHI cron handlers are re-entrancy-guarded by a 30-minute transient lock; a tick that finds the lock held logs "already running" and exits rather than queuing. Both self-reschedule a single follow-up event 60 seconds later (`wp_schedule_single_event`) when there's more chunked work left to do within the same week — so a single weekly trigger can fan out into many rapid sub-ticks until that source's queue drains, rather than waiting a full week between chunks.

***


## 4. Lock Management

### 4.1 Lock Pattern

\[N] Every cron handler follows this pattern:

php

    $lock_key = 'blomstra_{pillar}_refresh_in_progress';
    $lock = get_transient($lock_key);
    if ($lock !== false && (time() - (int)$lock) < $lock_ttl) {
        blomstra_update_cron_status($pillar, 'running', 'Already running – skipping duplicate.');
        return;
    }
    set_transient($lock_key, time(), $lock_ttl);
    // ... do work ...
    delete_transient($lock_key);

\[N] If a lock exists and is within TTL, the cron handler exits immediately (no retry, no queue). If a lock is stale (beyond TTL), the next run will acquire it and proceed.


### 4.2 Lock TTLs

| Lock Key                                      | TTL    | Pillar        |
| :-------------------------------------------- | :----- | :------------ |
| `blomstra_hhi_refresh_in_progress`            | 30 min | HHI           |
| `blomstra_eia_refresh_in_progress`            | 30 min | EIA           |
| `blomstra_wb_refresh_in_progress`             | 30 min | WB Indicators |
| `blomstra_imf_weekly_in_progress`             | 10 min | IMF           |
| `blomstra_maritime_weekly_in_progress`        | 10 min | Maritime      |
| `blomstra_countries_async_in_progress`        | 10 min | Country List  |
| `blomstra_reporters_async_in_progress`        | 10 min | Reporter Map  |
| `{slug}_build_lock` (e.g., `sivi_build_lock`) | 30 min | Index Build   |


### 4.3 Build Lock (Index-Specific)

\[D] The SIVI build lock (`sivi_build_lock`) is acquired at the start of `blomstra_build_index_composite()` and released at the end. A `register_shutdown_function` safety net ensures the lock is cleared even on a fatal PHP error mid-build.

\[N] If a build lock is held, the auto-refresh cron skips and logs, rather than queuing or retrying.

***


## 5. Health States & Monitoring

### 5.1 Health State Taxonomy

\[D] Every pillar and index exists in exactly one of these states, stored in `blomstra_cron_status`:

| State       | Icon | Meaning                                     | Action                       |
| :---------- | :--- | :------------------------------------------ | :--------------------------- |
| `success`   | 🟢   | Last run completed successfully, data fresh | None                         |
| `partial`   | 🟡   | Last run completed, some data missing       | Monitor; retry if persistent |
| `running`   | 🔵   | Currently executing                         | Wait                         |
| `stuck`     | 🔴   | Lock exists but run timed out               | Clear lock, retry            |
| `error`     | 🔴   | Permanent failure or validation failed      | Investigate logs             |
| `retryable` | 🟠   | Transient failure, will retry automatically | Wait for next cron           |
| `stale`     | 🟡   | Data exists but exceeds freshness threshold | Refresh recommended          |
| `never-run` | ⚪    | No data, no pointer, no lock                | Initialize                   |


### 5.2 Health Status Storage

\[D] `blomstra_update_cron_status($pillar, $status, $message, $count = 0)` stores:

php

    [
        'last_attempt'  => '2026-09-09 08:00:00',
        'last_success'  => '2026-09-08 08:00:00',  // only if status == 'success'
        'timestamp'     => 1725888000,
        'status'        => 'success'|'partial'|'error'|'running'|'retryable'|'stuck',
        'message'       => 'Human-readable status message',
        'count'         => 182,  // e.g., countries cached
    ]


### 5.3 Staleness Thresholds

\[D] `blomstra_is_stale($pillar, $threshold = null)` checks freshness using these defaults:

| Pillar          | Threshold | Action if Stale              |
| :-------------- | :-------- | :--------------------------- |
| `wb_indicators` | 7 days    | Refresh recommended          |
| `eia`           | 7 days    | Refresh recommended          |
| `hhi`           | 7 days    | Refresh recommended          |
| `maritime`      | 7 days    | Refresh recommended          |
| `imf`           | 7 days    | Refresh recommended          |
| `countries`     | 30 days   | Refresh recommended          |
| `reporters`     | 30 days   | Refresh recommended          |
| `landlocked`    | 6 months  | Manual verification required |
| `sivi`          | 7 days    | Rebuild recommended          |


### 5.4 Monitoring Surfaces

\[D] The following monitoring surfaces are available:

| Surface               | Location                           | What It Shows                                                         |
| :-------------------- | :--------------------------------- | :-------------------------------------------------------------------- |
| API Keys Status       | `blomstra_check_api_keys_status()` | Boolean present/absent for each credentialed source                   |
| Cron Status           | `blomstra_cron_status` option      | Per-source running/success/partial/error state with timestamps        |
| Snapshot Summary      | `admin_notices` hook               | Per index-slug/period, country count and last-recorded timestamp      |
| Fetch Logs            | Ring-buffered options              | Comtrade (50), EIA (200), WB (50), IMF (50) — per API call outcomes   |
| Data Health Dashboard | Admin UI                           | Full table with status, coverage, state breakdown, action suggestions |

\[D] The `admin_notices` snapshot summary is the fastest way to visually confirm "did last night's build actually produce a full-coverage snapshot."

***


## 6. Action Suggestions

\[D] The admin UI generates action suggestions based on health state and data availability:

| Condition                                           | Action Suggestion                                               |
| :-------------------------------------------------- | :-------------------------------------------------------------- |
| Cache empty                                         | ⚠️ Cache is empty – use "Refresh" below                         |
| Pointer incomplete and status ∈ \[success, partial] | ⏳ Refresh in progress – next step will run shortly              |
| Status == partial, !pointer\_incomplete, cache > 0  | ⚠️ Some data could not be fetched. Use "Refresh" below to retry |
| Status == success && !stale                         | ✅ Up to date – no action needed                                 |
| Stuck && lock\_exists                               | 🔒 Stuck – click "Refresh" below to clear lock and retry        |
| never-run                                           | ⏳ Never run – use "Refresh" below                               |
| Stale                                               | ⏳ Stale – refresh recommended                                   |
| Error or retryable                                  | ⚠️ Error – check logs, then use "Refresh" below                 |
| Running                                             | 🔄 Running – wait for completion                                |

***


## 7. Alert System Operations

### 7.1 Alert Configuration

\[D] `blomstra_get_alert_config()` (option `blomstra_alert_config`):

| Setting          | Default                      | Description                        |
| :--------------- | :--------------------------- | :--------------------------------- |
| `enabled`        | 0                            | Enable/disable alert system        |
| `email`          | `blomstrainsights@gmail.com` | Email recipient                    |
| `webhook_url`    | ''                           | Custom webhook endpoint            |
| `slack_url`      | ''                           | Slack webhook URL                  |
| `store_alerts`   | 1                            | Store alerts in database           |
| `retention_days` | 45                           | Auto-delete alerts older than this |
| `max_per_index`  | 500                          | Max alerts kept per index          |

\[N] The alert system is disabled by default (`enabled = 0`). It must be explicitly enabled via the admin UI.


### 7.2 Change Detection

\[D] `blomstra_alert_detect_changes()` compares new vs. old per-country data for an index and classifies each country as:

| Type       | Condition                                                                       |
| :--------- | :------------------------------------------------------------------------------ |
| `new`      | Wasn't in the old data                                                          |
| `excluded` | Was in old, absent from new (e.g., dropped below minimum pillar coverage)       |
| `change`   | Rank moved, score moved by ≥0.01, or any individual pillar score moved by ≥0.01 |

\[N] A country with none of the above produces no alert row at all — the 0.01 thresholds exist specifically to avoid alert noise from floating-point-level non-changes.


### 7.3 Deduplication & Cooldown

\[N] Before firing, an MD5 hash of the entire changes array is compared against the hash from the last alert fired for that index (stored in a 5-minute-default cooldown transient, filterable via `blomstra_alert_cooldown`). An identical change set within the cooldown window is silently skipped — this guards against, e.g., a debounced SIVI rebuild and the 03:00 daily safety-net rebuild both firing for the same underlying data change and double-alerting.


### 7.4 Delivery: Background by Default

\[N] When `store_alerts` is on (default), `blomstra_fire_index_alerts()` only stores the alert rows and schedules `blomstra_deliver_alerts` \~10 seconds later — it does not send email/webhook/Slack inline during the build.

\[D] `blomstra_deliver_pending_alerts()` (the actual sender):

1. Re-reads the stored rows

2. Reconstructs the changes array (including re-deriving pillar deltas from JSON columns if the stored `alert_reason` mentions a pillar change)

3. Builds the notification payload

4. Sends all three channels (email, webhook, Slack)

5. Marks `sent_at`

\[D] If `store_alerts` is off, delivery happens synchronously inline during the build instead — a deliberate fallback, not the normal path, since inline delivery adds webhook/email latency directly to the build's execution time.

\[D] All three channels are independent and best-effort — a webhook failure doesn't block email or Slack, and none of the three failing blocks the alert being marked `sent_at` (there's no per-channel delivery confirmation or retry).


### 7.5 Message Content

\[D] Email and Slack both branch on `total > 10`:

- Above 10: Condensed summary (counts by type: new/excluded/rank-movers/score-movers/pillar-changes) plus top-10 (email) or top-5 (Slack) detail list with a link to the admin panel for the rest

- At or below 10: Every change gets a full detail line

\[D] Webhook payload (`payload_version: 2`) always includes every change in full, plus the same summary counts, plus a human-readable one-line summary per change (`change['human']`) for consumers that don't want to re-derive formatting themselves.


### 7.6 Retention

\[D] Daily cleanup (`blomstra_alert_cleanup_cron`, one hour after first activation, then daily):

- Deletes `log_type = 'alert'` rows older than `retention_days`

- Trims each index's alert history down to the most recent `max_per_index` rows regardless of age

\[N] Non-`alert` log rows (info/warning entries from `blomstra_alert_log()`, e.g., "alerts disabled, skipping") are not subject to this cleanup — only `log_type = 'alert'` rows are pruned, so system log noise persists indefinitely unless addressed separately.

***


## 8. Recovery Scenarios

\[D] Compiled from the failure/lock/guard behaviors already documented in `DATA.md` and `SIVI.md`, restated here as an operator-facing runbook:

| Symptom                                      | Likely Cause                                                               | What Already Handles It                                                                                                 | Manual Action Needed                                                                                                           |
| :------------------------------------------- | :------------------------------------------------------------------------- | :---------------------------------------------------------------------------------------------------------------------- | :----------------------------------------------------------------------------------------------------------------------------- |
| A pillar hasn't updated in a long time       | API key missing/expired, or repeated quota exhaustion                      | Universe-aware promotion gate (Invariant #3) prevents bad partial data from ever overwriting good production data       | Check `blomstra_check_api_keys_status()` and the relevant call log                                                             |
| SIVI build never runs                        | Build lock (`sivi_build_lock`) stuck                                       | 30-minute TTL + shutdown-function safety net auto-clears it even after a fatal error                                    | Wait out the TTL, or manually `delete_transient('sivi_build_lock')` if urgent                                                  |
| Country count in SIVI suddenly drops         | Upstream source had a bad/partial fetch                                    | Build-failure coverage-drop guard (`SIVI.md` §13.3) discards the bad build automatically and keeps the prior one        | Investigate the upstream source's fetch/promotion logs; no data was lost                                                       |
| Backfill for a specific year never completes | `sivi_backfill_lock` stuck, or that year's job stuck in `running`          | `sivi_backfill_check_completion()` clears the lock once every year reaches a terminal state                             | Check `blomstra_cache_job_get_status()`/backfill status option for the specific year                                           |
| Duplicate alerts for the same change         | N/A — this is the case the cooldown exists to prevent                      | 5-minute dedup cooldown (§7.3)                                                                                          | None — working as designed                                                                                                     |
| Alert never arrives despite `enabled: true`  | One delivery channel misconfigured, or `store_alerts` transient/cron issue | Each channel fails independently; check `blomstra_alert_log` entries and the `blomstra_alerts` table's `sent_at` column | Verify channel config (email/webhook/Slack URL); confirm `blomstra_deliver_alerts` actually fired (WP-Cron dependent — see §9) |


### 8.1 Emergency Lock Clear Commands

| Situation                | Command                                                |
| :----------------------- | :----------------------------------------------------- |
| HHI lock stuck           | `wp transient delete blomstra_hhi_refresh_in_progress` |
| EIA lock stuck           | `wp transient delete blomstra_eia_refresh_in_progress` |
| SIVI build lock stuck    | `wp transient delete sivi_build_lock`                  |
| SIVI backfill lock stuck | `wp transient delete sivi_backfill_lock`               |

Admin UI alternative: Click "Refresh" on the affected pillar in the Data Health Dashboard — this clears the lock and schedules a new run.


### 8.2 Emergency Flush All

\[D] If the system is in an unrecoverable state:

Admin UI: Click "Emergency Flush ALL Caches" on the Reference Data page. This deletes:

- All L1 transient caches (countries, reporters, maritime, WB indicators, IMF)

- All L1 persistent options (HHI, EIA, cron status, summaries, logs)

- All pointers (HHI, EIA, WB)

- Flushes WB and IMF indicator caches

\[N] Warning: This is destructive. After this, you must manually refresh all pillars and rebuild the index.

***


## 9. WP-Cron Caveat

\[N] Every scheduled job in this system (weekly source refreshes, daily SIVI refresh, alert cleanup, alert delivery, EIA/HHI self-rescheduling sub-ticks) relies on WordPress's default pseudo-cron, which only fires on an incoming page request.

\[D] A low-traffic site can silently fall behind on all of the above if nothing triggers WP-Cron — this is a WordPress-wide operational consideration, not specific to this plugin, but is worth stating explicitly here since so much of this system's correctness (fresh data, timely alerts, backfill completion) depends on cron actually running on schedule.

\[N] A real server-level cron hitting `wp-cron.php` (or disabling WP-Cron's pseudo-trigger in favor of one) is the standard mitigation, external to this codebase.

Production cron setup (recommended):

Option A (WP-CLI):

bash

    # crontab -e
    */5 * * * * cd /var/www/html && wp cron event run --due-now > /dev/null 2>&1

Option B (curl):

bash

    # crontab -e
    */5 * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1

***


## 10. Backup & Restore

### 10.1 What to Backup

| Data                           | Location                                       | Frequency      |
| :----------------------------- | :--------------------------------------------- | :------------- |
| L1 caches (HHI, EIA, Maritime) | `wp_options` (blomstra\_\*)                    | Weekly         |
| Composite index                | `sivi_composite_index`                         | Weekly         |
| Historical snapshots           | `wp_blomstra_index_history`                    | Weekly         |
| Alerts                         | `wp_blomstra_alerts`                           | Monthly        |
| Cron status / summaries        | `wp_options` (blomstra\_cron\_\*, \*\_summary) | After each run |


### 10.2 Backup Commands

Options:

sql

    SELECT option_name, option_value FROM wp_options 
    WHERE option_name LIKE 'blomstra_%' OR option_name LIKE 'sivi_%' 
    INTO OUTFILE '/tmp/blomstra_backup.sql';

Custom Tables:

bash

    mysqldump -u user -p database wp_blomstra_index_history wp_blomstra_alerts wp_blomstra_historical_data wp_blomstra_cache_jobs > blomstra_tables.sql


### 10.3 Restore

sql

    -- Restore options
    UPDATE wp_options SET option_value = '{backup_json}' WHERE option_name = 'sivi_composite_index';

    -- Restore tables
    mysql -u user -p database < blomstra_tables.sql

\[N] After restoring a backup, verify pillar health and rebuild the index if necessary.

***


## 11. Open Questions

\[D] These are operational/product decisions that require explicit resolution:

| #  | Question                                                                                                                                               | Notes                                     |
| :- | :----------------------------------------------------------------------------------------------------------------------------------------------------- | :---------------------------------------- |
| 1  | Monitoring integration – Should we integrate with external monitoring (e.g., New Relic, Sentry) for health alerts?                                     | Currently only admin UI and `error_log()` |
| 2  | Alert escalation – Should critical failures (e.g., build failure, API outage) trigger external alerts (email, Slack) even if alert system is disabled? | Currently disabled by default             |
| 3  | Maintenance window – Should we define a maintenance window for scheduled cron jobs (e.g., 02:00-04:00 UTC)?                                            | Currently jobs are spread across the week |
| 4  | SLAs – What are the expected freshness SLAs for each data pillar?                                                                                      | Currently "weekly" with no formal SLA     |
| 5  | Disaster recovery – What is the RTO (Recovery Time Objective) and RPO (Recovery Point Objective)?                                                      | Not defined                               |

***


## 12. Corrections to Prior Documentation

\[D] The following corrections are made from prior documentation:

| Prior Claim                                   | Correction                                                                       | Source                                |
| :-------------------------------------------- | :------------------------------------------------------------------------------- | :------------------------------------ |
| Alert system sent notifications synchronously | Alert system uses background delivery via cron (v2.1.0 "offloads notifications") | `blomstra-index-alerts.php` header    |
| No cooldown mechanism existed                 | 5-minute dedup cooldown prevents duplicate alerts                                | `blomstra_alert_cooldown_*` transient |
| All log rows were cleaned up                  | Only `log_type = 'alert'` rows are pruned; system logs persist                   | `blomstra_alert_cleanup()` SQL        |
| SIVI only refreshed at 03:00 daily            | SIVI also refreshes on-demand within 60 seconds of any source cron completion    | `sivi_maybe_auto_refresh_after_rd()`  |
| No operational runbook existed                | This document is the first dedicated operational write-up                        | New document                          |

***


## End of Operations Specification

Status: CANONICAL
