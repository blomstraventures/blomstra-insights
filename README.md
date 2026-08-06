# Blomstra Insights

**A multi-index strategic intelligence platform built on WordPress.**

Blomstra Insights collects data from public sources (World Bank, UN Comtrade, EIA), normalizes it through a shared reference layer, computes composite vulnerability indices via transparent methodology, and presents them through a generic, config-driven frontend engine.

---

## What This Is

- **7–10 granular indices** covering geopolitical risk, mineral dependency, governance capture, energy vulnerability, maritime exposure, and more.
- **3–4 composite global models** that combine granular indices into flagship products with maps, country profiles, and narrative analysis.
- **Fully transparent methodology** — every score is traceable to a public data source. No black-box estimation.
- **Academic-grade rigor** — percentile-rank normalization, OECD/JRC-compliant partial-index handling, structural-zero rules for landlocked states.

---

## A note on naming

The first index built on this system is internally named **CII** in every function name, REST slug, and shortcode (`cii_build_composite()`, `/wp-json/blomstra/v1/critical-infrastructure-index`, `[cii_index]`, `cii_cron_status`, etc.). Its intended full name is **Critical Infrastructure Vulnerability Index (CIV)** — the rename is a deliberately deferred, tracked task, not yet done. Documentation in this repo uses **CII** to match what's actually in the code, so grepping the codebase for anything mentioned in these docs won't come up empty. Don't be surprised if an older diagram or note says "CIV" — treat "CII" as current truth until the rename actually lands.

---

## Documentation

| Document | Purpose |
|---|---|
| [docs/01-architecture.md](docs/01-architecture.md) | System layers, design principles, migration history, build-reliability pattern |
| [docs/02-data-flow.md](docs/02-data-flow.md) | Raw API → composite pipeline, fallback paths |
| [docs/03-api-contract.md](docs/03-api-contract.md) | REST endpoint schemas, required fields, response shapes |
| [docs/04-frontend-engine.md](docs/04-frontend-engine.md) | Widget architecture, data-* API, state lifecycle |
| [docs/05-index-template.md](docs/05-index-template.md) | Step-by-step checklist for building a new index |
| [docs/06-deployment.md](docs/06-deployment.md) | WPCode workflow, cron setup, troubleshooting |
| [docs/07-glossary.md](docs/07-glossary.md) | Terminology and definitions |
| [docs/08-reference-data-functions.md](docs/08-reference-data-functions.md) | Function-by-function reference for every shared Reference Data utility |
| [docs/09-methodology-deepdive.md](docs/09-methodology-deepdive.md) | The reasoning behind percentile normalization and partial-index rank ranges — the part not reconstructable from code alone |

---

## Quick Start

1. **Install Reference Data** — paste `Utility - Global Reference data.php` into WPCode (Run Everywhere)
2. **Add API keys** to `wp-config.php`:
   ```php
   define('COMTRADE_PRIMARY_KEY', 'your-key');
   define('EIA_API_KEY', 'your-key');
   ```
3. **Install an index backend** — e.g. `cii-backend.php`
4. **Install the frontend** — index-frontend-styles.css + index-frontend-engine.js (site-wide), then the cii-shortcode.php
5. **Build the index** — visit the admin page, refresh pillars, build composite
6. **Add the shortcode** to any page: `[cii_index]`

---

## Repository Structure

```
blomstra-insights/
├── README.md
├── docs/
│   ├── 01-architecture.md
│   ├── 02-data-flow.md
│   ├── 03-api-contract.md
│   ├── 04-frontend-engine.md
│   ├── 05-index-template.md
│   ├── 06-deployment.md
│   ├── 07-glossary.md
│   ├── 08-reference-data-functions.md
│   └── 09-methodology-deepdive.md
├── assets/
│   ├── diagram-01-system-architecture.png
│   ├── diagram-02-data-pipeline.png
│   ├── diagram-03-rank-assignment.png
│   └── diagram-04-frontend-architecture.png
├── src/
│   ├── reference-data/
│   │   └── global-reference-data.php
│   ├── indices/
│   │   └── cii/
│   │       ├── cii-backend.php
│   │       └── cii-shortcode.php
│   └── frontend/
│       ├── index-frontend-engine.js
│       └── index-frontend-styles.css
└── .gitignore
```

---

## License

Proprietary — Blomstra Insights. All rights reserved.
