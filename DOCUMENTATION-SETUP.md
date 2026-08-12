# Blomstra Insights — Automated Documentation System

This repository uses a dual‑layer documentation approach:

- **Human‑written guides** in `docs/` → System architecture, methodologies, and operational guides.
- **Auto‑extracted API reference** from `src/` → PHP and JavaScript function signatures, parameters, and comments.

The entire documentation site is automatically rebuilt and deployed to GitHub Pages via a custom GitHub Actions workflow whenever changes are pushed to the `main` branch.  
**Live documentation:** [https://blomstraventures.github.io/blomstra-insights/](https://blomstraventures.github.io/blomstra-insights/)

---

## 🚀 How It Works

| Layer | Source Directory | Generated Output | Description |
|-------|------------------|------------------|-------------|
| **Interactive Code API** | `./src/` (PHP & JS) | `api-reference.html` | Function signatures, JSDoc/PHPDoc comments, parameters, constants, and hooks. Includes a live search interface. |
| **System Architecture** | `./docs/` (`.md` files) | `[filename].html` | High‑level methodology, data flows, BMS‑1.0.0 specifications, and operational guides converted to styled dark‑mode HTML. |
| **Central Portal Hub** | Generated at build | `index.html` | The main landing page linking to all documentation resources in one unified hub. |

---

## 📁 Folder & File Structure

### `.github/workflows/`

Contains the GitHub Actions workflow that orchestrates the documentation build and deployment.

> **If the folder doesn't exist:** Create it with `mkdir -p .github/workflows`.

| File | Purpose |
|------|---------|
| [`generate-docs.yml`](https://github.com/blomstraventures/blomstra-insights/blob/main/.github/workflows/generate-docs.yml) | **Primary workflow.** Triggers on pushes to `main`. Sets up PHP 8.2, runs the documentation generator, and deploys the output to the `gh-pages` branch. |

### `scripts/`

Contains the PHP script that powers the entire documentation generation process.

> **If the folder doesn't exist:** Create it with `mkdir scripts`.

| File | Purpose |
|------|---------|
| [`generate-docs.php`](https://github.com/blomstraventures/blomstra-insights/blob/main/scripts/generate-docs.php) | **Complete documentation generator.** Scans `src/` for PHP and JavaScript files, extracts PHPDoc/JSDoc comments, generates a searchable API reference, converts all Markdown files from `docs/` to styled HTML, builds a portal `index.html`, and produces machine‑readable metadata (`api.json`, `docs_manifest.json`). |

### `docs/`

Contains all human‑written documentation in Markdown format. These files are automatically converted to styled HTML by the generator.

> **If the folder doesn't exist:** Create it with `mkdir docs`.

| File | Description |
|------|-------------|
| [`00-read-me-first.md`](https://github.com/blomstraventures/blomstra-insights/blob/main/docs/00-read-me-first.md) | Naming conventions, version policy, and how to navigate the docs. |
| [`01-architecture.md`](https://github.com/blomstraventures/blomstra-insights/blob/main/docs/01-architecture.md) | System architecture, design principles, and the three‑layer risk model. |
| [`02-data-flow.md`](https://github.com/blomstraventures/blomstra-insights/blob/main/docs/02-data-flow.md) | Data pipeline and flow details. |
| [`03-api-contract.md`](https://github.com/blomstraventures/blomstra-insights/blob/main/docs/03-api-contract.md) | REST API contract and endpoint specifications. |
| [`04-frontend-engine.md`](https://github.com/blomstraventures/blomstra-insights/blob/main/docs/04-frontend-engine.md) | Frontend widget engine documentation. |
| [`05-index-template.md`](https://github.com/blomstraventures/blomstra-insights/blob/main/docs/05-index-template.md) | Template for building new indices (BMS‑1.0.0 compliant). |
| [`06-deployment.md`](https://github.com/blomstraventures/blomstra-insights/blob/main/docs/06-deployment.md) | Deployment guide and environment setup. |
| [`07-glossary.md`](https://github.com/blomstraventures/blomstra-insights/blob/main/docs/07-glossary.md) | Glossary of key terms (BMS, composite, coverage, injection, etc.). |
| [`08-reference-data-functions.md`](https://github.com/blomstraventures/blomstra-insights/blob/main/docs/08-reference-data-functions.md) | Reference data layer functions and usage. |
| [`09-index-utilities.md`](https://github.com/blomstraventures/blomstra-insights/blob/main/docs/09-index-utilities.md) | Index utilities and helper functions. |
| [`10-methodology-deepdive.md`](https://github.com/blomstraventures/blomstra-insights/blob/main/docs/10-methodology-deepdive.md) | Deep dive into percentile normalisation, weighting, and methodology. |
| [`11-engineering-research-standards.md`](https://github.com/blomstraventures/blomstra-insights/blob/main/docs/11-engineering-research-standards.md) | Engineering and research standards documentation. |
| [`deviations.md`](https://github.com/blomstraventures/blomstra-insights/blob/main/docs/deviations.md) | Documented deviations from BMS‑1.0.0 (excluded from public site). |

> **Note:** `deviations.md` and anything under `docs/internal/` are **excluded** from the public documentation site. See the `EXCLUDED_DOC_FILES` and `EXCLUDED_DOC_DIRS` constants in `scripts/generate-docs.php`.

### `assets/`

Contains diagrams and visual assets used in the documentation.

> **If the folder doesn't exist:** Create it with `mkdir assets`.

| File | Description |
|------|-------------|
| [`diagram-01-three-layer-risk-model.png`](https://github.com/blomstraventures/blomstra-insights/blob/main/assets/diagram-01-three-layer-risk-model.png) | Three‑layer risk model diagram. |
| [`diagram-02-system-architecture.png`](https://github.com/blomstraventures/blomstra-insights/blob/main/assets/diagram-02-system-architecture.png) | System architecture diagram. |
| [`diagram-03-data-pipeline.png`](https://github.com/blomstraventures/blomstra-insights/blob/main/assets/diagram-03-data-pipeline.png) | Data pipeline diagram. |
| [`diagram-04-frontend-architecture.png`](https://github.com/blomstraventures/blomstra-insights/blob/main/assets/diagram-04-frontend-architecture.png) | Frontend architecture diagram. |
| [`diagram-04-seri-pillars.png`](https://github.com/blomstraventures/blomstra-insights/blob/main/assets/diagram-04-seri-pillars.png) | SERI pillars diagram. |
| [`diagram-05-sivi-pillars.png`](https://github.com/blomstraventures/blomstra-insights/blob/main/assets/diagram-05-sivi-pillars.png) | SIVI pillars diagram. |
| [`diagram-06-percentile-normalization.png`](https://github.com/blomstraventures/blomstra-insights/blob/main/assets/diagram-06-percentile-normalization.png) | Percentile normalisation diagram. |
| [`diagram-07-rank-assignment.png`](https://github.com/blomstraventures/blomstra-insights/blob/main/assets/diagram-07-rank-assignment.png) | Rank assignment diagram. |

> **Note:** These assets are referenced from Markdown files in `docs/` using relative paths (e.g., `../assets/diagram-01.png`). The generator preserves these paths in the output HTML.

### `src/frontend/`

Contains the shared CSS file:

> **If the folder doesn't exist:** Create it with `mkdir -p src/frontend`.

| File | Purpose |
|------|---------|
| [`index-frontend-styles.css`](https://github.com/blomstraventures/blomstra-insights/blob/main/src/frontend/index-frontend-styles.css) | Used by **both** the live index frontend and the documentation site, ensuring a consistent visual identity across all Blomstra products. |

---

## 🎨 Visual Features & Styling

- **Brand Alignment:** The documentation site uses the same `index-frontend-styles.css` as the live indices, ensuring a consistent look and feel across the entire product.
- **Dark‑Mode Optimised:** All pages use a dark theme matching the Blomstra brand.
- **Mermaid.js Diagrams:** Any Markdown code block using ` ```mermaid ` is automatically rendered as an interactive SVG diagram.
- **Back‑to‑Top Scrolling:** A smooth‑scroll button is automatically added to all documentation pages for easy navigation.
- **Live Search:** The API reference includes a searchable function index.

---

## ⚙️ GitHub Pages Configuration

To enable automatic deployment, you must set the **build source** to **GitHub Actions**:

1. Go to your repository **Settings → Pages**.
2. Under **"Build and deployment"**, find the **"Source"** dropdown.
3. Select **"GitHub Actions"**.
4. Save the settings.

> **Note:** The [`generate-docs.yml`](https://github.com/blomstraventures/blomstra-insights/blob/main/.github/workflows/generate-docs.yml) workflow is the source of truth for the build process. It defines the environment, dependencies, and steps required to generate and deploy the documentation site. Once the source is set to GitHub Actions, every push to `main` will automatically trigger the workflow.

---

## 🚀 Triggering a Manual Build

You can manually trigger the workflow at any time:

1. Go to the repository **Actions** tab.
2. Select the **"Generate Blomstra Insights API Docs"** workflow from the left sidebar.
3. Click **"Run workflow"** → select the branch → click **"Run workflow"**.

---

## 📂 Adding New Documentation

| Type | Action |
|------|--------|
| **New Architecture Guide** | Add a new Markdown file to `./docs/` (e.g., `docs/12-new-feature.md`). It will be automatically converted to HTML and added to the portal grid. |
| **New Code Function** | Add standard PHPDoc or JSDoc comments above functions in `src/`. The parser will extract them automatically into `api-reference.html`. |
| **Style Changes** | Modify `src/frontend/index-frontend-styles.css` to update the appearance of both the documentation and the live frontend. |
| **New Diagram** | Add a new PNG file to `assets/` and reference it from the relevant Markdown file using `../assets/filename.png`. |

---

## 📄 Output Location

All generated HTML files and assets are built and deployed to the **`gh-pages`** branch of this repository. From there, GitHub Pages serves the live site.

**Live URL:** [https://blomstraventures.github.io/blomstra-insights/](https://blomstraventures.github.io/blomstra-insights/)

**Build Artifacts:** The generated HTML files are also available for download from the **"Artifacts"** section of each workflow run in the Actions tab. This is useful for debugging or manual inspection.

---

## 🛠 Troubleshooting

| Issue | Likely Cause | Solution |
|-------|--------------|----------|
| Site not updating after push | GitHub Pages source not set to Actions | Go to Settings → Pages and set Source to **GitHub Actions**. |
| API reference missing new functions | PHPDoc/JSDoc comments not properly formatted | Ensure comments are correctly placed and use standard tags (`@param`, `@return`, `@throws`, etc.). |
| CSS changes not reflecting | Browser cache or incorrect file path | Clear your browser cache and verify that the CSS file path in the generated HTML is correct. |
| Workflow fails | PHP script errors or missing permissions | Check the workflow logs in the Actions tab for detailed error messages. |
| Diagrams not displaying | Incorrect relative path in Markdown | Ensure diagrams are referenced as `../assets/filename.png` from within `docs/` files. |

---

## 📚 Related Documentation

Once the documentation site is built, these pages will be available at:

- [Read Me First](https://blomstraventures.github.io/blomstra-insights/00-read-me-first.html)
- [Architecture Overview](https://blomstraventures.github.io/blomstra-insights/01-architecture.html)
- [Data Flow](https://blomstraventures.github.io/blomstra-insights/02-data-flow.html)
- [API Contract](https://blomstraventures.github.io/blomstra-insights/03-api-contract.html)
- [Frontend Engine](https://blomstraventures.github.io/blomstra-insights/04-frontend-engine.html)
- [Index Template](https://blomstraventures.github.io/blomstra-insights/05-index-template.html)
- [Deployment Guide](https://blomstraventures.github.io/blomstra-insights/06-deployment.html)
- [Glossary](https://blomstraventures.github.io/blomstra-insights/07-glossary.html)
- [Reference Data Functions](https://blomstraventures.github.io/blomstra-insights/08-reference-data-functions.html)
- [Index Utilities](https://blomstraventures.github.io/blomstra-insights/09-index-utilities.html)
- [Methodology Deep Dive](https://blomstraventures.github.io/blomstra-insights/10-methodology-deepdive.html)
- [Engineering & Research Standards](https://blomstraventures.github.io/blomstra-insights/11-engineering-research-standards.html)
- [API Reference](https://blomstraventures.github.io/blomstra-insights/api-reference.html)

> **Note:** The `deviations.md` file is **intentionally excluded** from the public site. It remains in the repository for internal reference but is never published.

> **Also note:** These links will work once the documentation site has been built and deployed at least once. If you see a 404, run the workflow manually from the Actions tab to generate the site.

---

## 📌 Legacy CII (Critical Infrastructure Index)

The CII index has been superseded by **SIVI (Sovereign Infrastructure Vulnerability Index)**. All new development should use SIVI. The CII shortcode (`[cii_index]`) is preserved for backward compatibility but will be removed in a future release.

---

> **Note:** This documentation system is designed to be **zero‑maintenance** for code documentation. Any new PHP or JS function added to `src/` is automatically parsed and included in the API reference without manual intervention. The system scales with your codebase.
