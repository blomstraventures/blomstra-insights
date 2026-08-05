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

## Documentation

| Document | Purpose |
|---|---|
| [docs/01-architecture.md](docs/01-architecture.md) | System layers, design principles, migration history |
| [docs/02-data-flow.md](docs/02-data-flow.md) | Raw API → composite pipeline, fallback paths |
| [docs/03-api-contract.md](docs/03-api-contract.md) | REST endpoint schemas, required fields, response shapes |
| [docs/04-frontend-engine.md](docs/04-frontend-engine.md) | Widget architecture, data-* API, state lifecycle |
| [docs/05-index-template.md](docs/05-index-template.md) | Step-by-step checklist for building a new index |
| [docs/06-deployment.md](docs/06-deployment.md) | WPCode workflow, cron setup, troubleshooting |
| [docs/07-glossary.md](docs/07-glossary.md) | Terminology and definitions |

---

## Quick Start

1. **Install Reference Data** — paste `Utility - Global Reference data.php` into WPCode (Run Everywhere)
2. **Add API keys** to `wp-config.php`:
   ```php
   define('COMTRADE_PRIMARY_KEY', 'your-key');
   define('EIA_API_KEY', 'your-key');
   ```
3. **Install an index backend** — e.g. `Backend - CIV Critical Infra Vulnerability Index.php`
4. **Install the frontend** — CSS + JS snippets (site-wide), then the index shortcode PHP
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
│   └── 07-glossary.md
├── assets/
│   ├── diagram-01-system-architecture.png
│   ├── diagram-02-data-pipeline.png
│   ├── diagram-03-rank-assignment.png
│   └── diagram-04-frontend-architecture.png
├── src/
│   ├── reference-data/
│   │   └── blomstra-reference-data.php
│   ├── indices/
│   │   └── civ/
│   │       ├── backend.php
│   │       └── shortcode.php
│   └── frontend/
│       ├── blomstra-index-frontend-engine.js
│       └── blomstra-index-frontend-styles.css
└── .gitignore
```

---

## License

Proprietary — Blomstra Insights. All rights reserved.
