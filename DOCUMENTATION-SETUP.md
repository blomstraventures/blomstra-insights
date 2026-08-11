# 📚 Blomstra Insights — Dual Documentation System

This repository uses a **unified documentation architecture** combining human-written system design guides (`docs/`) with automated code-level function extraction (`src/`).

---

## 🏛️ How It Works

The documentation site is automatically built and deployed via the `.github/workflows/gemini-doc.yml` GitHub Action whenever code is pushed to `main`.

| Layer | Source Directory | Generated Output | Description |
| :--- | :--- | :--- | :--- |
| **Interactive Code API** | `./src/` (PHP & JS) | `api-reference.html` & `api.json` | Function signatures, JSDoc/PHPDoc comments, parameters, constants, and hooks. Includes live search. |
| **System Architecture** | `./docs/` (`.md` files) | `[filename].html` | High-level methodology, data flows, BMS-1.0.0 specs, and operational guides converted to styled dark-mode HTML. |
| **Central Portal Hub** | Generated at build | `index.html` | The main landing page linking all documentation resources in one unified hub. |

---

## 🎨 Visual Features & Styling

*   **Brand Alignment:** Palette configured to match Blomstra's Emerald Green (`#10b981`), Mint (`#34d399`), Amber Gold (`#f59e0b`), and Deep Slate (`#0f172a`).
*   **Mermaid.js Diagrams:** Any Markdown code block using ` ```mermaid ` is automatically rendered into an interactive SVG diagram.
*   **Back-to-Top Scrolling:** Automatic smooth scroll button added to all documentation pages.
*   **Zero-Maintenance Code Extraction:** Any new PHP/JS function added to `./src/` is automatically parsed without manual documentation updating.

---

## ⚙️ GitHub Pages Configuration

Ensure GitHub Pages is set to deploy via **GitHub Actions**:
1. Open Repository **Settings** ➔ **Pages**.
2. Set **Source** to `GitHub Actions`.
3. To trigger a manual build, go to **Actions** ➔ **`gemini-doc`** ➔ **Run workflow**.

---

## 🛠️ Adding New Documentation

*   **New Architecture Guide:** Add a new Markdown file into `./docs/` (e.g., `docs/12-new-feature.md`). It will automatically convert to HTML and be added to the portal grid.
*   **New Code Function:** Add standard PHPDoc or JSDoc comments above functions in `src/`. The parser will extract them automatically into `api-reference.html`.
