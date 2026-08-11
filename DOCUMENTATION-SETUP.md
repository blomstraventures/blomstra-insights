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

| File | Purpose |
|------|---------|
| [`generate-docs.yml`](https://github.com/blomstraventures/blomstra-insights/blob/main/.github/workflows/generate-docs.yml) | **Primary workflow.** Triggers on pushes to `main`. Sets up PHP 8.2, runs the documentation generator, and deploys the output to the `gh-pages` branch. |

### `scripts/`

Contains the PHP script that powers the entire documentation generation process.

| File | Purpose |
|------|---------|
| [`generate-docs.php`](https://github.com/blomstraventures/blomstra-insights/blob/main/scripts/generate-docs.php) | **Complete documentation generator.** Scans `src/` for PHP and JavaScript files, extracts PHPDoc/JSDoc comments, generates a searchable API reference, converts all Markdown files from `docs/` to styled HTML, builds a portal `index.html`, and produces machine‑readable metadata (`api.json`, `docs_manifest.json`). |

### `src/frontend/`

Contains the shared CSS file:

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

---

## 📚 Related Documentation

- [Blomstra Methodology Standard (BMS‑1.0.0)](https://blomstraventures.github.io/blomstra-insights/bms-1.0.0.html)
- [SIVI Methodology](https://blomstraventures.github.io/blomstra-insights/sivi-methodology.html)
- [SERI Methodology](https://blomstraventures.github.io/blomstra-insights/seri-methodology.html)
- [API Reference](https://blomstraventures.github.io/blomstra-insights/api-reference.html)

---

> **Note:** This documentation system is designed to be **zero‑maintenance** for code documentation. Any new PHP or JS function added to `src/` is automatically parsed and included in the API reference without manual intervention. The system scales with your codebase.
