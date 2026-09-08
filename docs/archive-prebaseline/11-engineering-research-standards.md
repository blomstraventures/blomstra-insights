# Engineering & Research Standards

> **Standard:** BMS-1.0.0
> **Purpose:** Code quality, research ethics, and architectural conformance rules

---

## BMS-1.0.0 Conformance Checklist

Every index must implement all 11 requirements:

1. [ ] **Per-pillar storage** -- `{index}_{pillar}_data` with `['data' => ..., 'sources' => ...]`
2. [ ] **Per-pillar meta** -- `{index}_{pillar}_meta` with `['last_fetched' => '...']`
3. [ ] **Composite storage** -- `{index}_composite_index` with scenario-safe builder
4. [ ] **Async callbacks** -- One hook per pillar: `{index}_async_fetch_{pillar}`
5. [ ] **Cron safeguards** -- Auto-rollback if new build < 80% of previous count
6. [ ] **Sensitivity testing** -- Presets, custom JSON, Spearman correlation
7. [ ] **Data quality scores** -- `blomstra_pillar_quality_score()` per country per pillar
8. [ ] **Measurement flags** -- Per country: coverage ratio, structural zeros, missing pillars
9. [ ] **Admin dashboard** -- Cards, freshness bar, pillar table, build section, sensitivity section
10. [ ] **REST endpoint** -- Canonical + legacy redirect
11. [ ] **Init validation** -- `blomstra_validate_pillar_thresholds()` on `init`

---

## Code Quality Rules

### Naming

- **Functions:** `{prefix}_{verb}_{noun}()` -- e.g., `sivi_refresh_energy_pillar()`
- **Constants:** `{PREFIX}_{NAME}` -- e.g., `SIVI_ENERGY_KEY`
- **Options:** `{prefix}_{descriptor}` -- e.g., `sivi_energy_data`
- **Transients:** `{prefix}_{pillar}_{iso3}` -- e.g., `sivi_energy_USA`
- **Hooks:** `{prefix}_{action}` -- e.g., `sivi_async_fetch_energy`

### Error Handling

- **Never silently fail.** Every API call must log errors via `error_log()`.
- **Always return structured errors.** `array( 'error' => 'descriptive message' )`
- **Never cache null as zero.** Missing data must be stored as `null`, not `0`.
- **Never impute without documentation.** If you must fill missing data, flag it in measurement_flags.

### Security

- **Nonce every form.** `wp_nonce_field( '{prefix}_{action}_action' )` + `check_admin_referer()`
- **Sanitize all inputs.** `sanitize_key()`, `sanitize_text_field()`, `absint()`
- **Escape all outputs.** `esc_html()`, `esc_attr()`, `esc_url()`
- **No eval().** No `create_function()`. No dynamic code execution.

### Performance

- **Batch API calls.** Never loop over 200 countries making individual API requests.
- **Use transients.** Cache per-country data with appropriate TTL (energy: 12h, maritime: 7d, HHI: 24h).
- **Checkpoint long runs.** Write partial results to options mid-run.
- **Set time limits.** `@set_time_limit( 600 )` for long fetches.

---

## Research Ethics

### Transparency

- **No hidden weights.** All weights are defined in `get_pillar_weights()` and `get_composite_weights()`.
- **No black-box models.** Every score is traceable to a public data source via `source` and `sources` arrays.
- **No data fabrication.** Missing data is reported as missing, not imputed with averages or zeros (except documented structural zeros).
- **Sensitivity testing is mandatory.** Every index must expose its robustness to weight changes.

### Honesty About Uncertainty

- **Partial indices get ranges, not points.** Never report a definitive rank for a country missing a pillar.
- **Forward pressure is labeled as projection.** Never present forecasts as facts.
- **Data quality is reported.** Every country object includes a `data_quality` score.

### No Gaming

- **No selective indicator dropping.** You cannot remove an indicator because it produces an inconvenient result for a specific country.
- **No post-hoc weight adjustment.** Weights are defined before seeing the data.
- **No cherry-picked time windows.** History windows (e.g., 5-year volatility) are fixed in the code, not adjusted per country.

---

## Version Control Standards

### Commit Messages

```
[SERI] Fix fiscal fallback merge logic
[SIVI] Add checkpointing to HHI fetch
[SHARED] Add blomstra_compute_median() utility
[DOCS] Update API contract for BMS-1.0.0
```

### Branching

- `main` -- production code, always deployable
- `dev-{index}` -- feature branches for new indices
- `fix-{issue}` -- bug fix branches

### Releases

Tag format: `{index}-v{semver}`

```
git tag seri-v4.2.1
git tag sivi-v2.0.0
git tag bms-1.0.0
```

---

## Documentation Standards

### Every Function Must Have

- **Purpose** -- What does it do?
- **Parameters** -- Type and description
- **Returns** -- Type and shape
- **Side effects** -- What options/transients does it update?

### Every Index Must Document

- **Methodology** -- How are scores computed?
- **Data sources** -- Primary and fallback for each indicator
- **Limitations** -- What does the index NOT measure?
- **Update frequency** -- How often does data refresh?
- **Coverage** -- How many countries? How many excluded? Why?

---

## Testing Checklist

Before deploying a new index version:

- [ ] Build composite from cache -- verify all countries scored
- [ ] Refresh each pillar individually -- verify freshness meta updates
- [ ] Build scenario -- verify baseline NOT overwritten
- [ ] Delete scenario -- verify clean removal
- [ ] Force cron -- verify auto-rollback if data loss simulated
- [ ] Test REST endpoint -- verify JSON validity
- [ ] Test legacy endpoint -- verify backward compatibility
- [ ] Test frontend widget -- verify no JS errors, multiple instances work
- [ ] Test on mobile -- verify responsive layout
- [ ] Check error logs -- zero new warnings

---

## Academic Host Requirements

For grant applications and PhD supervision:

- **Methodology paper** -- Publishable whitepaper explaining normalization, partial indices, and structural zeros
- **Replication package** -- Code + data (or data access instructions) sufficient to reproduce all scores
- **Sensitivity appendix** -- Full scenario comparison tables for all preset weight schemes
- **Source code availability** -- Open-source PHP (already satisfied)
- **API documentation** -- Machine-readable schema (OpenAPI spec planned)
