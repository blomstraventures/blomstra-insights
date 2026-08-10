# Blomstra Insights

**A multi-index strategic intelligence platform built on WordPress.**

Blomstra Insights collects data from public sources (World Bank, UN Comtrade, EIA, IMF WEO), normalizes it through a shared reference layer, computes composite vulnerability indices via transparent methodology, and presents them through a generic, config-driven frontend engine.

---

## What This Is

- **3 foundation indices** (live): SERI (structural resilience), SIVI (infrastructure vulnerability), with GPRI (geopolitical risk) in development.
- **1 paid overlay product** (planned): Geoeconomic Atlas — sanctions exposure, supply-chain chokepoints, energy dependence, financial fragmentation.
- **Fully transparent methodology** — every score is traceable to a public data source. No black-box estimation.
- **Academic-grade rigor** — percentile-rank normalization, OECD/JRC-compliant partial-index handling, structural-zero rules, sensitivity testing, forward-pressure forecasting.
- **BMS-1.0.0 conformant** — all indices follow the Blomstra Methodology Standard for storage, API shape, admin UI, and cron architecture.

---

## The Three-Layer Stack

| Layer | Index | Concept | Measures | Access |
|-------|-------|---------|----------|--------|
| **Foundation** | **SERI** | Resilience | Governance, macro stability, fiscal stress, external vulnerability | Free / Open |
| **Systems** | **SIVI** | Vulnerability | Energy dependency, supplier concentration (HHI), maritime connectivity | Free / Open |
| **Events** | **GPRI** | Risk | Conflict, security, alliances, political violence | Free / Open (planned) |
| **Exposure** | **Geoeconomic Atlas** | Atlas | Sanctions, chokepoints, fragmentation | Paid / API (planned) |

---

## Documentation

| Document | Purpose |
|---|---|
| [docs/00-read-me-first.md](docs/00-read-me-first.md) | Start here — naming, version policy, how to read these docs |
| [docs/01-architecture.md](docs/01-architecture.md) | System layers, design principles, BMS-1.0.0 standard, migration history |
| [docs/02-data-flow.md](docs/02-data-flow.md) | Raw API → composite pipeline, fallback paths, checkpointing |
| [docs/03-api-contract.md](docs/03-api-contract.md) | REST endpoint schemas for SERI and SIVI, required fields, response shapes |
| [docs/04-frontend-engine.md](docs/04-frontend-engine.md) | Widget architecture, data-* API, state lifecycle, clash-proof event handling |
| [docs/05-index-template.md](docs/05-index-template.md) | Step-by-step checklist for building a new BMS-1.0.0 conformant index |
| [docs/06-deployment.md](docs/06-deployment.md) | WPCode workflow, cron setup, troubleshooting, API keys |
| [docs/07-glossary.md](docs/07-glossary.md) | Terminology and definitions |
| [docs/08-reference-data-functions.md](docs/08-reference-data-functions.md) | Function-by-function reference for shared Reference Data utilities |
| [docs/09-index-utilities.md](docs/09-index-utilities.md) | Shared math, ranking, and validation utilities used by all indices |
| [docs/10-methodology-deepdive.md](docs/10-methodology-deepdive.md) | Percentile normalization, partial-index rank ranges, structural zeros |
| [docs/11-engineering-research-standards.md](docs/11-engineering-research-standards.md) | BMS-1.0.0 conformance rules, code quality, research ethics |
| [docs/deviations.md](docs/deviations.md) | Known issues, deferred renames, and intentional deviations |

---

## Quick Start

1. **Install Reference Data** — paste `shared/blomstra-index-utilities.php` into WPCode (Run Everywhere)
2. **Add API keys** to `wp-config.php`:
   ```php
   define('COMTRADE_PRIMARY_KEY', 'your-key');
   define('EIA_API_KEY', 'your-key');
   ```
3. **Install an index backend** — e.g. `src/indices/seri/seri-backend.php`
4. **Install the frontend** — `src/frontend/index-frontend-styles.css` + `src/frontend/index-frontend-engine.js` (site-wide), then add the shortcode
5. **Build the index** — visit the admin page, refresh pillars, build composite
6. **Add the shortcode** to any page: `[seri_index]` or `[sivi_index]`

---

## Repository Structure

```
blomstra-insights/
├── README.md
├── docs/
│   ├── 00-read-me-first.md
│   ├── 01-architecture.md
│   ├── 02-data-flow.md
│   ├── 03-api-contract.md
│   ├── 04-frontend-engine.md
│   ├── 05-index-template.md
│   ├── 06-deployment.md
│   ├── 07-glossary.md
│   ├── 08-reference-data-functions.md
│   ├── 09-index-utilities.md
│   ├── 10-methodology-deepdive.md
│   ├── 11-engineering-research-standards.md
│   ├── deviations.md
│   ├── api/
│   │   ├── indices/
│   │   │   ├── seri.md
│   │   │   └── sivi.md
│   │   ├── reference-data/
│   │   │   └── shared-utilities.md
│   │   └── frontend/
│   │       └── widget-api.md
│   └── assets/
│       └── (diagrams)
├── src/
│   ├── shared/
│   │   └── blomstra-index-utilities.php
│   │   └── global-reference-data.php
│   ├── indices/
│   │   ├── seri/
│   │   │   ├── seri-backend.php
│   │   │   └── seri-shortcode.php
│   │   └── sivi/
│   │       ├── sivi-backend.php
│   │       └── sivi-shortcode.php
│   └── frontend/
│       ├── index-frontend-engine.js
│       └── index-frontend-styles.css
└── .gitignore
```

---

## License

Proprietary — Blomstra Insights. All rights reserved.
