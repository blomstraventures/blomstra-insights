# Blomstra Insights — Automated Documentation System

This repository uses a dual‑layer documentation approach:

- **Human‑written guides** in `docs/` → System architecture, methodologies, and operational guides.
- **Auto‑extracted API reference** from `src/` → PHP and JavaScript function signatures, parameters, and comments.

The entire documentation site is automatically rebuilt and deployed to GitHub Pages via a custom GitHub Actions workflow whenever changes are pushed to the `main` branch.  
**Live documentation:** [https://blomstraventures.github.io/blomstra-insights/](https://blomstraventures.github.io/blomstra-insights/)

---

## How It Works

| Layer | Source Directory | Generated Output | Description |
|-------|------------------|------------------|-------------|
| **Interactive Code API** | `./src/` (PHP & JS) | `api-reference.html` | Function signatures, JSDoc/PHPDoc comments, parameters, constants, and hooks. Includes a live search interface. |
| **System Architecture** | `./docs/` (`.md` files) | `[filename].html` | High‑level methodology, data flows, BMS‑1.0.0 specifications, and operational guides converted to styled dark‑mode HTML. |
| **Central Portal Hub** | Generated at build | `index.html` | The main landing page linking to all documentation resources in one unified hub. |

---

## Folder & File Structure

The documentation pipeline relies on the following key directories and files:

### `.github/workflows/`

Contains the GitHub Actions workflow files that orchestrate the entire documentation build and deployment process.

| File | Purpose |
|------|---------|
| [`gemini-doc.yml`](https://github.com/blomstraventures/blomstra-insights/blob/main/.github/workflows/gemini-doc.yml) | **Primary workflow.** Triggers on pushes to `main`. Sets up PHP, installs dependencies, runs the PHP documentation generator, and deploys the output to the `gh-pages` branch. |
| [`kimi-docs.yml`](https://github.com/blomstraventures/blomstra-insights/blob/main/.github/workflows/kimi-docs.yml) | **Secondary workflow.** Likely used for testing, alternative builds, or documentation previews. |

### `scripts/`

Contains the PHP scripts that parse your source code and generate the API documentation.

| File | Purpose |
|------|---------|
| [`generate-gemini-docs.php`](https://github.com/blomstraventures/blomstra-insights/blob/main/scripts/generate-gemini-docs.php) | **Primary generator.** Scans the `src/` directory, extracts PHPDoc and JSDoc blocks from PHP and JavaScript files, and builds the `api-reference.html` file with a searchable interface. |
| [`generate-kimi-docs.php`](https://github.com/blomstraventures/blomstra-insights/blob/main/scripts/generate-kimi-docs.php) | **Alternative generator.** May produce a different format or serve as a backup/experimental version. |

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

> **Note:** The [`gemini-doc.yml`](https://github.com/blomstraventures/blomstra-insights/blob/main/.github/workflows/gemini-doc.yml) workflow is the source of truth for the build process. It defines the environment, dependencies, and steps required to generate and deploy the documentation site. Once the source is set to GitHub Actions, every push to `main` will automatically trigger the workflow.

---

## 🚀 Triggering a Manual Build

You can manually trigger the workflow at any time:

1. Go to the repository **Actions** tab.
2. Select the **gemini-doc** workflow from the left sidebar.
3. Click **"Run workflow"** → select the branch → click **"Run workflow"**.

This is useful for testing changes before they are merged to `main`.

---

## 📂 Adding New Documentation

| Type | Action |
|------|--------|
| **New Architecture Guide** | Add a new Markdown file to `./docs/` (e.g., `docs/12-new-feature.md`). It will be automatically converted to HTML and added to the portal grid. |
| **New Code Function** | Add standard PHPDoc or JSDoc comments above functions in `src/`. The parser will extract them automatically into `api-reference.html`. |
| **New Build Script** | Add a new `.php` file to the `scripts/` folder and update the workflow YAML to include the new step. |
| **Style Changes** | Modify `src/frontend/index-frontend-styles.css` to update the appearance of both the documentation and the live frontend. |

---

## 📄 Output Location

All generated HTML files and assets are built and deployed to the **`gh-pages`** branch of this repository. From there, GitHub Pages serves the live site.

**Live URL:** [https://blomstraventures.github.io/blomstra-insights/](https://blomstraventures.github.io/blomstra-insights/)

**Build Artifacts:** The generated HTML files (including `index.html`, `api-reference.html`, and all converted Markdown pages) are also available for download from the **"Artifacts"** section of each workflow run in the Actions tab. This is useful for debugging or manual inspection.

---

## 🔧 Customising the Build

| What to Change | Where to Look |
|----------------|---------------|
| **CSS** | Modify `src/frontend/index-frontend-styles.css`. Changes apply to both the documentation and the live frontend. |
| **Workflow** | Edit `.github/workflows/gemini-doc.yml` to adjust PHP versions, add new build steps, or modify deployment settings. |
| **PHP Scripts** | Update the relevant `.php` files in the `scripts/` folder to change how documentation is parsed, rendered, or structured. |
| **Portal Layout** | Edit the HTML generation logic inside `generate-gemini-docs.php` or `generate-kimi-docs.php` to customise the index page layout. |

---

## 🧪 Testing Locally

To test the documentation build locally before pushing:

1. Clone the repository.
2. Ensure PHP is installed locally.
3. Run the generator script directly:
   ```bash
   php scripts/generate-gemini-docs.php
