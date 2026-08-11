# Blomstra Insights — Automated Documentation System

This repository uses a dual‑layer documentation approach:

- **Human‑written guides** in `docs/` → System architecture, methodologies, and operational guides.
- **Auto‑extracted API reference** from `src/` → PHP and JavaScript function signatures, parameters, and comments.

The entire documentation site is automatically rebuilt and deployed to GitHub Pages via a custom GitHub Actions workflow whenever changes are pushed to the `main` branch.  
**Live documentation:** [https://blomstraventures.github.io/blomstra-insights/](https://blomstraventures.github.io/blomstra-insights/)

---

## 🚀 Getting Started – Setting Up the Documentation Pipeline

If you're setting this up for the first time, follow these steps to ensure everything works correctly.

### Step 1: Create the `scripts/` Folder and Add the PHP Generator

1.  In the root of your repository, create a new folder named `scripts/`.
2.  Inside `scripts/`, create a new PHP file called `generate-gemini-docs.php`.
3.  Copy the contents from [this link](https://github.com/blomstraventures/blomstra-insights/blob/main/scripts/generate-gemini-docs.php) into your new file.

**What this PHP script does:**

- Recursively scans the `src/` directory for `.php` and `.js` files.
- Extracts PHPDoc and JSDoc comment blocks (`/** ... */`) to collect:
  - Function/method names
  - Parameters and their types
  - Return types
  - Descriptions
  - Version tags (`@since`, `@deprecated`)
- Organises the extracted data into a structured format.
- Generates a single, searchable **`api-reference.html`** page with a clean dark‑theme interface.
- Applies the shared CSS (`src/frontend/index-frontend-styles.css`) to ensure brand consistency.

---

### Step 2: Create the `.github/workflows/` Folder and Add the YAML Workflow

1.  In the root of your repository, create a new folder named `.github/workflows/`.
2.  Inside `.github/workflows/`, create a new YAML file called `gemini-doc.yml`.
3.  Copy the contents from [this link](https://github.com/blomstraventures/blomstra-insights/blob/main/.github/workflows/gemini-doc.yml) into your new file.

**What this YAML workflow does:**

| Section | Purpose |
|---------|---------|
| **Trigger (`on`)** | Runs automatically when code is pushed to the `main` branch. Can also be triggered manually via the GitHub Actions UI. |
| **Environment (`runs-on`)** | Spins up a fresh `ubuntu-latest` virtual machine to run the build. |
| **Checkout** | Pulls the latest code from your repository. |
| **Setup PHP** | Installs the required PHP version (e.g., 8.2) using the `shivammathur/setup-php` action. |
| **Run Generator** | Executes `php scripts/generate-gemini-docs.php` to create the HTML documentation. |
| **Deploy to Pages** | Uses the `peaceiris/actions-gh-pages` action to commit the generated files to the `gh-pages` branch, which GitHub Pages then serves. |

> **Note:** The workflow output (HTML files) is deployed to the **`gh-pages`** branch of your repository. Do not edit this branch manually — the workflow overwrites it on every successful run.

---

### Step 3: Configure GitHub Pages to Use Actions as the Source

By default, GitHub Pages may be set to deploy from a branch (e.g., `main` or `gh-pages`). To use the automated workflow, you must change the source to **GitHub Actions**.

1.  Go to your repository on GitHub.
2.  Click on **Settings** (the tab at the top).
3.  In the left sidebar, click **Pages** (under "Code and automation").
4.  Under **"Build and deployment"**, find the **"Source"** dropdown.
5.  Change the selection from **"Deploy from a branch"** to **"GitHub Actions"**.
6.  The page will refresh, and you'll see a confirmation that your site is now built and deployed by the Actions workflow.

> **Important:** If you do not change this to **GitHub Actions**, your workflow will still run, but GitHub Pages will not serve the generated files. The site will remain outdated or show a 404. Setting the source to Actions ensures GitHub Pages looks at the output of your workflow instead of a static branch.

---

### Step 4: Run the Workflow Manually (Optional)

The workflow runs automatically on every push to `main`. However, you can also trigger it manually if you want to test changes without pushing code.

1.  Go to your repository on GitHub.
2.  Click on the **Actions** tab.
3.  In the left sidebar, find and select the **"gemini-doc"** workflow.
4.  Click the **"Run workflow"** button (a dropdown will appear).
5.  Select the branch (usually `main`) and click **"Run workflow"** again.
6.  Wait a few moments. You will see a new workflow run appear in the list. Click on it to watch the build progress in real time.

---

### Step 5: View the Generated Documentation

Once the workflow completes successfully:

- **Live Site:** The documentation is automatically available at:
  [https://blomstraventures.github.io/blomstra-insights/](https://blomstraventures.github.io/blomstra-insights/)

- **Build Artifacts (for debugging):** You can also download the generated files from the workflow run.
  1. Go to the **Actions** tab.
  2. Click on the specific workflow run.
  3. Scroll down to the **"Artifacts"** section.
  4. Download the `gh-pages` artifact to inspect the HTML files locally.

---

## 🔧 How the System Works (Detailed)

| Layer | Source Directory | Generated Output | Description |
|-------|------------------|------------------|-------------|
| **Interactive Code API** | `./src/` (PHP & JS) | `api-reference.html` | Function signatures, JSDoc/PHPDoc comments, parameters, constants, and hooks. Includes a live search interface. |
| **System Architecture** | `./docs/` (`.md` files) | `[filename].html` | High‑level methodology, data flows, BMS‑1.0.0 specifications, and operational guides converted to styled dark‑mode HTML. |
| **Central Portal Hub** | Generated at build | `index.html` | The main landing page linking to all documentation resources in one unified hub. |

---

## 🎨 Visual Features & Styling

- **Brand Alignment:** The documentation site uses the same [`index-frontend-styles.css`](https://github.com/blomstraventures/blomstra-insights/blob/main/src/frontend/index-frontend-styles.css) as the live indices, ensuring a consistent look and feel across the entire product.
- **Dark‑Mode Optimised:** All pages use a dark theme matching the Blomstra brand.
- **Mermaid.js Diagrams:** Any Markdown code block using ` ```mermaid ` is automatically rendered as an interactive SVG diagram.
- **Back‑to‑Top Scrolling:** A smooth‑scroll button is automatically added to all documentation pages for easy navigation.
- **Live Search:** The API reference includes a searchable function index.

---

## 📂 Adding New Documentation

| Type | Action |
|------|--------|
| **New Architecture Guide** | Add a new Markdown file to `./docs/` (e.g., `docs/12-new-feature.md`). It will be automatically converted to HTML and added to the portal grid. |
| **New Code Function** | Add standard PHPDoc or JSDoc comments above functions in `src/`. The parser will extract them automatically into `api-reference.html`. |
| **New Build Script** | Add a new `.php` file to the `scripts/` folder and update the workflow YAML to include the new step. |
| **Style Changes** | Modify `src/frontend/index-frontend-styles.css` to update the appearance of both the documentation and the live frontend. |

---

## 🧪 Testing Locally

To test the documentation build locally before pushing:

1.  Clone the repository.
2.  Ensure PHP is installed locally (version 8.0 or higher recommended).
3.  Run the generator script directly:
    ```bash
    php scripts/generate-gemini-docs.php


---

## ✅ What's New in This Version

| Section | What Was Added / Improved |
|---------|---------------------------|
| **Step 1: `scripts/` folder** | Explicit instructions to create the folder and file, plus a detailed breakdown of what the PHP script does. |
| **Step 2: `.github/workflows/` folder** | Explicit instructions to create the folder and file, plus a table explaining every part of the YAML workflow. |
| **Step 3: GitHub Pages configuration** | Step‑by‑step instructions to navigate to Settings → Pages and switch the source from "Branch" to "GitHub Actions". |
| **Step 4: Running the workflow** | Clear instructions on how to trigger a manual build from the Actions tab. |
| **Step 5: Viewing the output** | Where to find the live site and how to download build artifacts for debugging. |

This document now serves as a **complete onboarding guide** for anyone setting up the documentation pipeline for the first time — from folder creation to deployment.
