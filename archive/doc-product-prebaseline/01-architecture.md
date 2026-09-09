# Architecture

> **Applies to:** SERI v4.2.1+, SIVI v2.0.0+, all future indices
> **Standard:** BMS-1.0.0

---

## Design Principles

1. **Transparency over speed.** Every score must be traceable to a public data source. No proprietary black-box models.
2. **Graceful degradation.** Missing data is not a failure -- it is a signal. Partial indices are first-class citizens.
3. **Research-grade reproducibility.** Same inputs + same code = same outputs. No random seeds, no expert judgment, no hidden weights.
4. **Clash-proof frontend.** The widget engine must never conflict with other site JavaScript. No global variables, no prototype pollution.
5. **Scenario-safe sensitivity.** Testing different weights must never overwrite the live composite.

---

## System Layers

![System Architecture](../assets/diagram-02-system-architecture.png)

```
+-------------------------------------------------------------+
|                     FRONTEND LAYER                          |
|  WordPress page -> Shortcode -> Widget Engine -> Visualization |
|  (index-frontend-engine.js + index-frontend-styles.css)     |
+-------------------------------------------------------------+
                              |
                              v REST API
+-------------------------------------------------------------+
|                     INDEX LAYER                             |
|  SERI backend  |  SIVI backend  |  GPRI backend (planned)  |
|  +- Governance |  +- Energy     |                          |
|  +- Macro      |  +- HHI        |                          |
|  +- External   |  +- Maritime   |                          |
|  +- Fiscal     |                |                          |
|       v Composite Builder (scenario-safe)                  |
|       v Sensitivity Testing (Spearman correlation)         |
|       v Cron Safeguards (auto-rollback on data loss)       |
+-------------------------------------------------------------+
                              |
                              v shared utilities
+-------------------------------------------------------------+
|                  REFERENCE DATA LAYER                       |
|  blomstra-index-utilities.php                               |
|  +- Country lists (WB API + fallback)                      |
|  +- Batch fetchers (WB, IMF, Comtrade, EIA)              |
|  +- Math utilities (percentiles, stddev, CAGR, Spearman) |
|  +- Source tracking (provenance, quality scoring)          |
|  +- Rank builders (full + partial with injection)          |
+-------------------------------------------------------------+
                              |
                              v raw APIs
+-------------------------------------------------------------+
|                     DATA SOURCES                            |
|  World Bank | IMF WEO | UN Comtrade | EIA | UCDP (planned)|
+-------------------------------------------------------------+
```

---

## The Three-Layer Risk Model

![Three-Layer Risk Model](../assets/diagram-01-three-layer-risk-model.png)

Blomstra Insights does not produce a single "risk score." It produces **three complementary lenses**:

| Layer | Question | Index | Update Frequency |
|-------|----------|-------|------------------|
| **Structural foundations** | How capable is the state of absorbing shocks? | SERI | Quarterly/Annual |
| **System vulnerability** | How exposed are essential infrastructure systems? | SIVI | Quarterly/Annual |
| **Event risk** | Is the neighborhood on fire? | GPRI | Monthly/Event-driven |
| **Exposure overlay** | What weapons are pointed at you? | Geoeconomic Atlas | Quarterly (paid) |

A country's total risk is the **interaction** of these layers, not their sum.

---

## BMS-1.0.0 Conformance

### Storage Architecture

Every pillar stores data as a **two-key structure**:

```php
update_option( 'sivi_energy_data', array(
    'data'    => array( 'USA' => array('value' => 12.5, 'year' => 2024, 'source' => 'EIA'), ... ),
    'sources' => array( 'USA' => array( 'energy_dependency' => array( array('source' => 'EIA', 'scope' => 'national', 'year' => 2024) ) ) ),
), false );
```

This enables:
- **Provenance tracking** -- every indicator value knows its origin
- **Quality scoring** -- `blomstra_pillar_quality_score()` uses the sources array
- **Crash recovery** -- checkpoints can merge partial data without losing source metadata

### Async Architecture

Each pillar has its own async hook:

```php
// Scheduled by admin button or cron
do_action( 'sivi_async_fetch_energy' );

// Handler
function sivi_async_fetch_energy_callback() {
    sivi_refresh_energy_pillar( null, 'auto' );
}
add_action( 'sivi_async_fetch_energy', 'sivi_async_fetch_energy_callback' );
```

This prevents shared-host timeouts by breaking a 5-minute fetch into 3x 90-second background tasks.

### Cron Safeguards

```php
if ( $context === 'cron' && $previous && ! empty( $previous['countries'] ) ) {
    $prev_count = count( $previous['countries'] );
    $new_count = count( $output['countries'] );
    if ( $new_count < 0.8 * $prev_count && $new_count < 50 ) {
        // Keep old composite, log failure, set transient alert
        return $previous;
    }
}
```

This prevents a single API outage from wiping your live index.

### Scenario-Safe Builder

```php
function sivi_build_composite( $force = false, $context = 'manual', $custom_weights = null, $custom_composite_weights = null ) {
    $is_scenario = ( $custom_weights !== null || $custom_composite_weights !== null );
    // ... compute ...
    if ( ! $is_scenario && $context !== 'scenario' ) {
        update_option( SIVI_OPTION_KEY, $output, false );
    }
    return $output;
}
```

Sensitivity testing can never overwrite the live index.

---

## Migration History

| Date | Change | From | To |
|---|---|---|---|
| 2026-08 | SERI architecture migration | GERI v3.x | SERI v4.2.1 (BMS-1.0.0) |
| 2026-08 | SIVI architecture migration | CII v1.0.0 | SIVI v2.0.0 (BMS-1.0.0) |
| 2026-08 | Naming standardization | GERI / CII / CIVI | SERI / SIVI / GPRI |
| 2026-08 | BMS standard introduced | Ad-hoc per-index | BMS-1.0.0 unified architecture |

---

## File Organization

```
src/
├── shared/
│   └── blomstra-index-utilities.php    # BMS-1.0.0 shared layer
│   └── global-reference-data.php       # Reference Data layer
├── indices/
│   ├── seri/
│   │   ├── seri-backend.php            # BMS conformant
│   │   └── seri-shortcode.php
│   └── sivi/
│       ├── sivi-backend.php            # BMS conformant
│       └── sivi-shortcode.php
└── frontend/
    ├── index-frontend-engine.js        # Generic, config-driven
    └── index-frontend-styles.css
```

Every index backend is **self-contained** except for the shared utilities. No cross-index dependencies.
