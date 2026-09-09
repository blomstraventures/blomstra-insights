# DEVELOPMENT.md – Blomstra Insights Engineering & Development Standards

Document version: 1.0.0\
Status: CANONICAL\
Applies to: All code in the Blomstra Insights repository\
Last verified against commit: (to be filled)\
Source files: All `src/` files, PR templates, governance documents\
Effective date: 2026-09-09

***


## Document Control

| Field                   | Value                                                                                                                                                                                                             |
| :---------------------- | :---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Document                | `DEVELOPMENT.md` v1.0.0                                                                                                                                                                                           |
| Verified against commit | (to be filled)                                                                                                                                                                                                    |
| Source files            | All `src/` files, `.github/PULL_REQUEST_TEMPLATE.md`, `docs/BLOMSTRA-SPECIFICATION.md`                                                                                                                            |
| Method                  | Synthesis of the five prior code-verified documents into a forward-looking contribution process. This document is procedural rather than a fresh code inventory — no new source reading was required to write it. |
| Status                  | Complete for current code. Items in §11 are open questions, not implementation gaps.                                                                                                                              |

Statements below are \[N] normative (a rule; violating it in future code is a bug) or \[D] descriptive (today's implementation; may change without violating a rule).

***


## Table of Contents

1. Overview

2. The Five Engineering Invariants – Non-Negotiable

3. Layer Boundaries – Where New Code Goes

4. Naming Conventions

5. Code Quality Rules

6. Security Rules

7. Performance Rules

8. Testing Requirements

9. Pull Request Process

10. Versioning & Change Management

11. Documentation Synchronization

12. How to Add a New Index

13. Known Rough Edges

14. Open Questions

15. Corrections to Prior Documentation

***


## 1. Overview

\[D] This document defines the engineering standards, development rules, and process requirements for the Blomstra Insights repository. It is derived from the canonical specification (§15-16), the codebase patterns, and the five prior documents in this set.

\[N] All developers (human and AI) working on this repository MUST follow these rules. Deviations MUST be recorded in the deviation log.

\[N] The rules in this document are normative — violating them is a bug. Descriptive sections (marked \[D] ) describe current implementation patterns that may change without violating a rule.

***


## 2. The Five Engineering Invariants – Non-Negotiable

\[N] Every change to this codebase must preserve the five invariants established in `ARCHITECTURE.md` §7:

| #  | Invariant                                                                                                                                          | Confirmed At                                                     | Failure Mode                                                    |
| :- | :------------------------------------------------------------------------------------------------------------------------------------------------- | :--------------------------------------------------------------- | :-------------------------------------------------------------- |
| 1  | Bookkeeping key isolation – a builder's lock/staging key must never equal the key its output is persisted under                                    | `SIVI.md` §13 (`sivi_composite_internal` vs. `SIVI_OPTION_KEY`)  | Alerts stop firing; lock flags lost                             |
| 2  | Snapshot row integrity – every live build and historical backfill uses the one shared snapshot-row function                                        | `SIVI.md` §10 (`blomstra_build_flat_snapshot_row()`, v3.3.0 fix) | Hand-built rows miss required fields, causing blank charts      |
| 3  | Universe-aware promotion gate – a pillar/source's promotion threshold is judged against its own fetchable universe, never the global country count | `DATA.md` §4.3, §5.2 (HHI and EIA, REF-BUG-2)                    | Narrow-coverage pillars (Nuclear) never promote                 |
| 4  | Partial-progress promotion on quota exhaustion – staged data is promoted before returning, never discarded wholesale                               | `DATA.md` §4.3 (REF-BUG-1)                                       | Successful data lost; full restart wasted                       |
| 5  | Config-driven frontend – no hardcoded index-specific field names in the engine                                                                     | `FRONTEND.md` §3 (`data-biw-*` attribute contract)               | Empty CSV columns, broken sort, silent failures for new indices |

\[N] A pull request that violates one of these is a defect, not a stylistic disagreement — reviewers should block on it the same way they'd block on a security issue.

***


## 3. Layer Boundaries – Where New Code Goes

\[N] Per `ARCHITECTURE.md` §2:

| Layer | Where It Goes                              | What It Must Do                                    | What It Must NOT Do                                                  |
| :---- | :----------------------------------------- | :------------------------------------------------- | :------------------------------------------------------------------- |
| L1    | `global-reference-data.php` or new L1 file | Expose index-agnostic getters                      | Reference any index slug; depend on L2/L3                            |
| L2    | `blomstra-index-utilities.php`             | Provide generic math usable by more than one index | Perform external HTTP calls; render admin pages                      |
| L3    | `src/indices/{slug}/`                      | Define pillars, weights, reshape generic output    | Compute percentiles/rankings/DQI itself; bypass L2's generic builder |
| L4    | `src/frontend/`                            | Be driven by new `data-biw-*` attribute            | Hardcode index-specific checks inside the engine                     |

\[N] If a change seems to require crossing a layer boundary (e.g., "L2 needs to know this is SIVI to format it correctly"), that's a signal the abstraction is wrong — either the formatting belongs in L3 (which calls L2 and reshapes the output itself, as SIVI's `sivi_build_composite()` does), or the L2 function needs a more general parameter, not an index-slug branch.

***


## 4. Naming Conventions

### 4.1 Functions

\[N] Functions MUST follow the pattern:

text

    {prefix}_{verb}_{noun}()

| Scope        | Prefix      | Example                                    |
| :----------- | :---------- | :----------------------------------------- |
| Shared L1/L2 | `blomstra_` | `blomstra_compute_percentile_ranks_safe()` |
| SIVI         | `sivi_`     | `sivi_refresh_energy_pillar()`             |
| Future index | `{slug}_`   | `seri_build_composite()`                   |


### 4.2 Constants

\[N] Constants MUST follow the pattern:

text

    {PREFIX}_{NAME}

| Scope  | Example                    |
| :----- | :------------------------- |
| SIVI   | `SIVI_ENERGY_KEY`          |
| Shared | `BLOMSTRA_LANDLOCKED_ISO3` |


### 4.3 Options (wp\_options)

\[N] Options MUST follow the pattern:

text

    {prefix}_{descriptor}

| Scope            | Example                |
| :--------------- | :--------------------- |
| SIVI pillar data | `sivi_energy_data`     |
| SIVI pillar meta | `sivi_energy_meta`     |
| SIVI composite   | `sivi_composite_index` |
| Shared status    | `blomstra_cron_status` |


### 4.4 Transients

\[N] Transients MUST follow the pattern:

text

    {prefix}_{pillar}_{iso3}

| Scope                   | Example                            |
| :---------------------- | :--------------------------------- |
| SIVI energy per country | `sivi_energy_USA`                  |
| Shared lock             | `blomstra_hhi_refresh_in_progress` |


### 4.5 Hooks (WordPress actions/filters)

\[N] Hooks MUST follow the pattern:

text

    {prefix}_{action}

| Scope            | Example                          |
| :--------------- | :------------------------------- |
| SIVI async fetch | `sivi_async_fetch_energy`        |
| Shared cron      | `blomstra_cron_eia_weekly_event` |

***


## 5. Code Quality Rules

### 5.1 No Silent Failure

\[N] Every API call MUST log errors via `error_log()`.

php

    // Good
    if (is_wp_error($response)) {
        error_log('Blomstra: API fetch failed: ' . $response->get_error_message());
        return array();
    }

    // Bad
    if (is_wp_error($response)) {
        return array(); // Silent failure
    }


### 5.2 Always Return Structured Errors

\[N] Functions that can fail SHOULD return structured errors:

php

    // Good
    return array('error' => 'No country list available');

    // Good (REST)
    return new WP_Error('no_data', 'Index not built yet.', array('status' => 404));

    // Bad
    return false; // What does false mean?


### 5.3 Never Cache Null as Zero

\[N] Missing data MUST be stored as `null`, not `0`.

php

    // Good
    $data[$iso3] = null;

    // Bad
    $data[$iso3] = 0; // This is a real value!


### 5.4 No Imputation Without Documentation

\[N] If missing data is filled with a fallback value, it MUST be flagged in `measurement_flags` or `sources`.

php

    // Good
    if (!isset($primary_data[$iso3])) {
        $results[$iso3] = $fallback_data[$iso3];
        $flags[$iso3]['is_fallback'] = true;
    }

    // Bad
    $results[$iso3] = $fallback_data[$iso3]; // No flag


### 5.5 PHPDoc/JSDoc Required

\[N] Every public function MUST have PHPDoc (PHP) or JSDoc (JavaScript) documenting:

- Purpose (one sentence)

- Parameters (type and description)

- Returns (type and shape)

- Side effects (what options/transients it updates)

php

    /**
     * Compute percentile ranks with tie handling.
     *
     * @param array $values ISO3 => raw_value
     * @param float $winsor_pct Fraction to winsorize (0.0 = none)
     * @return array ISO3 => percentile (0-100)
     * @side-effect None – pure function
     */
    function blomstra_compute_percentile_ranks_safe($values, $winsor_pct = 0.0) { ... }

***


## 6. Security Rules

### 6.1 Nonce Every Form

\[N] Every WordPress admin form MUST use `wp_nonce_field()` and `check_admin_referer()`.

php

    // In form
    wp_nonce_field('sivi_fetch_energy_action');

    // In handler
    if (isset($_POST['sivi_fetch_energy']) && check_admin_referer('sivi_fetch_energy_action')) {
        // ...
    }


### 6.2 Sanitize All Inputs

\[N] All user inputs MUST be sanitized:

| Function                | Use                     |
| :---------------------- | :---------------------- |
| `sanitize_key()`        | Option keys, hook names |
| `sanitize_text_field()` | Free text               |
| `absint()`              | Integers                |
| `esc_url_raw()`         | URLs                    |

php

    $scenario_id = sanitize_key($_POST['scenario_id']);
    $year = absint($_POST['year']);
    $name = sanitize_text_field($_POST['name']);


### 6.3 Escape All Outputs

\[N] All outputs MUST be escaped:

| Function           | Use                            |
| :----------------- | :----------------------------- |
| `esc_html()`       | HTML content                   |
| `esc_attr()`       | HTML attributes                |
| `esc_url()`        | URLs                           |
| `esc_js()`         | JavaScript strings             |
| `wp_json_encode()` | JSON (with `JSON_HEX_*` flags) |

php

    echo '<div>' . esc_html($name) . '</div>';
    echo '<input value="' . esc_attr($value) . '">';
    echo '<a href="' . esc_url($url) . '">Link</a>';


### 6.4 No eval()

\[N] The following are FORBIDDEN:

- `eval()`

- `create_function()`

- Dynamic code execution (e.g., `eval("return $var;")`)

***


## 7. Performance Rules

### 7.1 Batch API Calls

\[N] Never loop over 200 countries making individual API requests.

php

    // Good
    $chunks = array_chunk($iso3_list, 50);
    foreach ($chunks as $chunk) {
        $data = fetch_batch($chunk);
    }

    // Bad
    foreach ($iso3_list as $iso3) {
        $data = fetch_one($iso3); // 200 API calls!
    }


### 7.2 Use Transients for Caching

\[N] Cache per-country/per-indicator data with appropriate TTL.

| Data Type          | TTL      |
| :----------------- | :------- |
| Energy per country | 12 hours |
| Maritime           | 7 days   |
| HHI                | 24 hours |
| Country list       | 1 day    |
| Reporter map       | 1 week   |


### 7.3 Checkpoint Long Runs

\[N] Write partial results to options mid-run to survive timeouts.

php

    // Checkpoint after each chunk
    $merged_cache = array_merge($existing_cache, $results);
    update_option($staging_key, $merged_cache, false);


### 7.4 Set Time Limits for Long Fetches

\[N] Use `@set_time_limit()` for operations that may exceed PHP's default (30s).

php

    if (function_exists('set_time_limit')) {
        @set_time_limit(900); // 15 minutes for HHI
    }

***


## 8. Testing Requirements

### 8.1 Pure Functions Must Be Tested

\[N] The following pure functions in `blomstra-index-utilities.php` MUST have unit tests:

| Function                                    | Priority | Description                              |
| :------------------------------------------ | :------- | :--------------------------------------- |
| `blomstra_compute_percentile_ranks_safe()`  | High     | Percentile computation with tie handling |
| `blomstra_compute_dqi()`                    | High     | Data Quality Index                       |
| `blomstra_compute_composite_dqi()`          | High     | Weighted DQI                             |
| `blomstra_compute_cagr()`                   | Medium   | CAGR from timeseries                     |
| `blomstra_project_partial_rank_composite()` | Medium   | Partial-rank projection                  |
| `blomstra_build_full_rank_display()`        | Medium   | Rank display object                      |
| `blomstra_build_partial_rank_display()`     | Medium   | Partial rank display                     |
| `blomstra_spearman_correlation()`           | Medium   | Spearman correlation                     |
| `blomstra_bootstrap_ci()`                   | Low      | Bootstrap sensitivity                    |


### 8.2 Test Targets

\[N] Tests MUST cover:

| Category       | Examples                           |
| :------------- | :--------------------------------- |
| Normal cases   | Valid inputs, expected outputs     |
| Edge cases     | Empty arrays, single items, ties   |
| Error cases    | Invalid inputs, null values        |
| Boundary cases | Extremes (0, 100, negative values) |


### 8.3 Testing Framework

\[D] Use PHPUnit (for PHP) and Jest or similar (for JavaScript). Tests should be in a `tests/` directory.

***


## 9. Pull Request Process

### 9.1 PR Checklist

\[N] Every pull request MUST verify:

markdown

    ## Pull Request Checklist

    - [ ] Code changes are tested and working
    - [ ] If architecture, contracts, methodology, or operations changed, `docs/BLOMSTRA-SPECIFICATION.md` is updated
    - [ ] Supporting documentation (if applicable) is updated
    - [ ] No old docs are referenced as current
    - [ ] PHPDoc/JSDoc is added for new functions
    - [ ] No silent failures are introduced
    - [ ] No missing values are treated as zero
    - [ ] The five engineering invariants are preserved (§2)


### 9.2 Required Reviews

\[N] The following types of changes require review:

| Change Type                  | Required Reviewers                                          |
| :--------------------------- | :---------------------------------------------------------- |
| Minor                        | At least 1 reviewer                                         |
| Major                        | At least 1 reviewer + research team (if methodology change) |
| Critical (production safety) | 2 reviewers + research team                                 |


### 9.3 Commit Messages

\[N] Commit messages SHOULD follow:

text

    [Component] Short description (50 chars)

    - Bullet points if needed
    - Explain why, not just what
    - Reference issue numbers if applicable

Examples:

text

    [SIVI] Add sensitivity interval to composite output

    - Compute bootstrap CI for full-coverage countries
    - Attach sensitivity_interval to country objects
    - Fixes #42

text

    [REF-DATA] Fix HHI quota partial promotion

    - When quota exhausted, promote staging data before stopping
    - Remaining countries stay in pointer for next run
    - This fixes silent data loss (REF-BUG-1)

***


## 10. Versioning & Change Management

### 10.1 Version Tracks

\[N] The following versions are conceptually distinct and MUST NOT be conflated:

| Track                     | Changes When                              | Example              |
| :------------------------ | :---------------------------------------- | :------------------- |
| Documentation version     | Doc structure/content changes             | v1.0.0               |
| Index methodology version | An index's scientific calculation changes | SIVI v3.3.0 → v4.0.0 |
| Software release          | Code changes, regardless of methodology   | insights-wp v3.1.2   |
| Reference data version    | Data state/release where applicable       | (varies)             |


### 10.2 Change Classification

\[N] Every change MUST be classified before implementation:

| Type  | Definition                                                                                 | Example                                     |
| :---- | :----------------------------------------------------------------------------------------- | :------------------------------------------ |
| Patch | No externally meaningful behaviour change                                                  | Typo fix, comment                           |
| Minor | Backward‑compatible functionality                                                          | New indicator; new admin option             |
| Major | Breaking change to contracts, architecture, methodology, data semantics, or interpretation | New pillar weight scheme; REST shape change |


### 10.3 Methodology Change Process

\[N] A methodological change (Major) MUST:

1. Be documented in a research review

2. Receive explicit sign-off from the research team

3. Bump the index methodology version (e.g., SIVI v3.3.0 → v4.0.0)

4. Include a sensitivity analysis comparing old vs new methodology

5. Update the public methodology documentation

***


## 11. Documentation Synchronization

### 11.1 When to Update Documentation

\[N] A code change that changes any of the following REQUIRES documentation review:

- Architecture

- Public API

- Frontend configuration contract

- Data schema

- Index calculation

- Data sources

- Data‑quality rules

- Operational behaviour

- Methodology


### 11.2 Same Change Set Rule

\[N] Documentation and code MUST be updated in the same change set.

Why: This prevents doc rot. If docs are updated later, they will be forgotten.

Implementation:

- Include doc updates in the same PR as code changes

- PR checklist includes a doc update checkbox

- The commit message references the doc changes


### 11.3 Documentation Verification

\[N] Every canonical release MUST state the exact commit SHA it was verified against.

markdown

    **Last verified against commit:** fc46520e8585a34c03221bbba983e510dd77ba51


### 11.4 Historical Documentation

\[N] When documentation is superseded, it MUST be:

1. Marked as HISTORICAL

2. Moved to `docs/archive/`

3. Never referenced as a current requirement

Reason: Historical documents are evidence, not authority.

***


## 12. How to Add a New Index

\[D] The established pattern (already followed by SIVI; SERI as the in-progress second example) — none of this is invented for this document, it's read off how SIVI is actually built:

| Step | What to Do                    | Where                       | Notes                                                                                                                                                                                                                                    |
| :--- | :---------------------------- | :-------------------------- | :--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1    | Define pillars and indicators | Research phase              | Check whether L1 already has a getter for each source; if not, write new L1 acquisition first                                                                                                                                            |
| 2    | Write L1 acquisition (if new) | `global-reference-data.php` | Follow existing patterns: simple sources get cached fetch; chunked sources use pointer-state-machine pattern with universe-aware promotion gate and partial-progress promotion on quota exhaustion — copy the pattern, don't reinvent it |
| 3    | Write pillar defs/weights     | `{slug}-backend.php`        | Mirror SIVI's `sivi_get_pillar_defs()`, `sivi_get_pillar_weights()`, `sivi_get_composite_weights()`                                                                                                                                      |
| 4    | Write generic config          | `{slug}-backend.php`        | `{slug}_get_generic_config()` — declare per-pillar winsorization, `post_percentile_transform`, `min_pillars_required`, sensitivity/benchmark settings                                                                                    |
| 5    | Write composite builder       | `{slug}-backend.php`        | Thin: call generic builder, reshape output to index's public schema, write to `{SLUG}_OPTION_KEY`. Never write to the public option from anywhere else                                                                                   |
| 6    | Wire refresh scheduling       | `{slug}-backend.php`        | Daily auto-refresh cron plus (recommended) event-driven refresh listening for relevant L1 weekly cron completions, debounced, per `DATA.md` §8/`SIVI.md` §12                                                                             |
| 7    | Register REST route           | `{slug}-backend.php`        | `GET /wp-json/blomstra/v1/{slug-name}`, public, returning full option, 404 `no_data` if never built, `Cache-Control: public, max-age=3600` — matching `API.md` §1's shape exactly                                                        |
| 8    | Write shortcode               | `{slug}-shortcode.php`      | Set every `data-biw-*` attribute per `FRONTEND.md` §3; no new frontend engine code needed for a standard dashboard/table index                                                                                                           |
| 9    | Wire historical backfill      | `{slug}-backend.php`        | `{slug}_build_historical_snapshot($year)` reusing year-specific L1 fetchers and the same generic builder with `skip_snapshot = true`, saving via `blomstra_index_snapshot_save()` with the correct year-keyed period                     |
| 10   | Write index documentation     | `docs/{SLUG}.md`            | Same style and rigor as `SIVI.md` — pillar formulas, exact constants, coverage rules, sensitivity/benchmark config, corrections section. This is not optional busywork — it's how the next rebuild avoids documentation drift            |

\[N] Steps 4–5 (delegate to the generic builder, reshape only) are where Invariant #3 (`blomstra_build_index_composite()` is the sole place composite math lives) is most likely to be accidentally violated by a new index under time pressure. Review new index PRs specifically for this.

***


## 13. Known Rough Edges

\[D] From direct code inspection during this documentation pass, tracked here rather than silently normalized into documentation as intended behavior:

| Issue                                                                                                 | Location                    | Status                                                                |
| :---------------------------------------------------------------------------------------------------- | :-------------------------- | :-------------------------------------------------------------------- |
| `openShareModal()` is a placeholder `alert()` referencing toolbar share buttons — not an actual modal | `index-frontend-engine.js`  | Known UI gap, not documented as intended behavior                     |
| `docs/api/` generated-reference folder contains stale CII documentation from before the rename        | `docs/api/indices/cii/`     | Should be deleted or regenerated                                      |
| `docs/UNIFIED SYSTEM SPECIFICATION.md.md` has a double `.md` extension                                | `docs/`                     | Should be renamed                                                     |
| The spec's document-control header has a blank "verified against commit" field                        | `BLOMSTRA-SPECIFICATION.md` | Should be filled per governance rule #7                               |
| SERI code exists in the repository but is not wired into any active REST route or public build        | `src/indices/seri/`         | Future work, not shipped product — confirmed by codebase verification |

***


## 14. Open Questions

\[D] These are process/engineering decisions that require explicit resolution:

| #  | Question                                                               | Notes                                                    |
| :- | :--------------------------------------------------------------------- | :------------------------------------------------------- |
| 1  | CI/CD – When should CI/CD be set up?                                   | Currently not configured; should run tests on every push |
| 2  | Test coverage – What is the minimum acceptable test coverage?          | Not defined                                              |
| 3  | Deviation log – Should deviations be tracked in code or documentation? | Currently in `deviations.md`                             |
| 4  | Code review tooling – Should we use GitHub PRs, or another tool?       | Currently GitHub                                         |
| 5  | Release cadence – How often should releases be cut?                    | Not defined                                              |
| 6  | Changelog – Should we maintain a CHANGELOG.md?                         | Currently not maintained                                 |

***


## 15. Corrections to Prior Documentation

\[D] The following corrections are made from prior documentation:

| Prior Claim                                               | Correction                                                                               | Source                                 |
| :-------------------------------------------------------- | :--------------------------------------------------------------------------------------- | :------------------------------------- |
| No "how to add a new index" runbook existed               | This document is the first version of that runbook written directly against current code | SIVI's actual implementation pattern   |
| Documentation updates could be separate from code changes | Documentation and code MUST be updated in the same change set                            | PR checklist, governance rules         |
| The five invariants were not formally documented as rules | All five are now promoted to MUST-level rules with failure modes                         | `ARCHITECTURE.md` §7, this document §2 |
| PHPDoc/JSDoc was optional                                 | PHPDoc/JSDoc is now REQUIRED for all public functions                                    | Code quality rules §5.5                |
| Testing was not defined                                   | Pure functions must have tests; coverage targets defined                                 | Testing requirements §8                |

***


## End of Development Specification

Status: CANONICAL
