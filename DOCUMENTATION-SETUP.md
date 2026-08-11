# Blomstra Insights — Auto-Documentation Setup

This folder contains everything needed to automatically generate developer documentation from your PHP source code and publish it to GitHub Pages.

---

## What It Does

On every push to `main` that changes a file in `src/`:

1. **Parses** all PHP files in `src/` (indices, shared utilities, frontend)
2. **Extracts** functions, constants, pillars, weights, hooks, and docblocks
3. **Generates**:
   - `api-reference.md` — Human-readable Markdown docs
   - `api.json` — Machine-readable JSON for integrations
   - `index.html` — Branded landing page (dark theme)
4. **Converts** Markdown to HTML with dark-theme styling
5. **Deploys** to GitHub Pages automatically

---

## One-Time Setup (5 Minutes)

### Step 1: Copy these files into your repo

```
blomstra-insights/
├── .github/
│   └── workflows/
│       └── kimi-docs.yml          <-- COPY THIS
├── scripts/
│   └── generate-kimi-docs.php     <-- COPY THIS
└── src/                      <-- YOUR EXISTING CODE
    ├── shared/
    ├── indices/
    └── frontend/
```

### Step 2: Enable GitHub Pages

1. Go to your repo on GitHub: `https://github.com/blomstraventures/blomstra-insights`
2. Click **Settings** → **Pages** (in the left sidebar)
3. Under **Source**, select **GitHub Actions**
4. Click **Save**

### Step 3: Push to main

```bash
git add .github/workflows/docs.yml scripts/generate-docs.php
git commit -m "ci: add auto-documentation pipeline"
git push origin main
```

### Step 4: Verify

1. Go to **Actions** tab in your GitHub repo
2. You should see a green checkmark for "Generate Blomstra API Docs"
3. Once complete, visit: `https://blomstraventures.github.io/blomstra-insights/`

---

## How to Trigger a Rebuild Manually

Go to **Actions** → **Generate Blomstra API Docs** → **Run workflow** → Select `main` branch → **Run workflow**.

Or simply push any change to a file in `src/`.

---

## What Gets Documented

The parser understands these Blomstra-specific patterns:

| Pattern | Extracted As | Example |
|---|---|---|
| `define('SIVI_VERSION', '2.0.0')` | Constant | `SIVI_VERSION = 2.0.0` |
| `function sivi_refresh_energy_pillar()` | Function with params | `sivi_refresh_energy_pillar($source)` |
| `/** Docblock above function */` | Description | Shown in API reference |
| `sivi_get_pillar_weights()` return array | Pillar definitions | Energy, HHI, Maritime |
| `add_action('sivi_async_fetch_energy')` | WordPress Hook | `sivi_async_fetch_energy` |

---

## Customization

### Add a new index

The parser auto-detects any PHP file in `src/indices/`. Just commit your new index backend and push — docs regenerate automatically.

### Change the landing page design

Edit the HTML inside `.github/workflows/docs.yml` in the "Generate Landing Hub" step. Or create a standalone `docs-site/index.html` in your repo.

### Add JSDoc for frontend

If you add JSDoc comments to `index-frontend-engine.js`, the parser can be extended to extract them. Currently it only parses PHP.

---

## Troubleshooting

| Problem | Fix |
|---|---|
| "Pages not found" | Check Settings → Pages → Source is set to GitHub Actions, not "Deploy from branch" |
| Workflow not running | Make sure you pushed to `main` (not `master` or another branch) |
| Empty docs output | Ensure your PHP files have at least some functions/constants. The parser skips empty files silently. |
| Images not showing | The workflow copies `assets/*` to `docs-site/assets/`. Make sure your diagrams are in the repo root `assets/` folder. |

---

## Security Note

This workflow only needs `pages: write` permission. It does NOT need access to your API keys, database, or WordPress installation. The parser runs entirely offline — no external API calls.
