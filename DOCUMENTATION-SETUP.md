# Blomstra Insights — Automated Documentation System

This repository uses a dual‑layer documentation approach:

- **Human‑written guides** in `docs/` → System architecture, methodologies, and operational guides.
- **Auto‑extracted API reference** from `src/` → PHP and JavaScript function signatures, parameters, and comments.

The entire documentation site is rebuilt and deployed to GitHub Pages via `scripts/generate-claude-docs.php`, run by `.github/workflows/claude-doc.yml` on every push to `main`.
**Live documentation:** [https://blomstraventures.github.io/blomstra-insights/](https://blomstraventures.github.io/blomstra-insights/)

> **v3.0 note:** Earlier versions of this pipeline had the actual site-generation
> logic (sidebar, search, portal, markdown rendering) duplicated inline inside
> the workflow YAML, separate from — and out of sync with — this script. That's
> fixed as of v3.0: `scripts/generate-gemini-docs.php` is now the single place
> this logic lives, and the workflow just calls it. If you're reading an older
> copy of this doc or an older `gemini-doc.yml`, the two will disagree with
> each other; this version is the accurate one.

---

## ⚠️ What never gets published

Not everything in `docs/` is meant to be public. `scripts/generate-claude-docs.php`
excludes:

- Any file whose **exact basename** is listed in `EXCLUDED_DOC_FILES` at the top
  of the script (currently: `deviations.md` — internal roadmap, open bugs, and
  paid-tier product notes have no business on a public site).
- Any file under a directory named in `EXCLUDED_DOC_DIRS` (currently: `internal`)
  — drop future internal-only docs in `docs/internal/` and they're excluded
  automatically, no script edit needed.

The workflow also runs an independent check after generation — if excluded
content is found in the output anyway, the deploy step is aborted rather than
publishing it. If you add a new internal doc, **use one of the two mechanisms
above**, don't rely on remembering to exclude it manually later.

---

## 🚀 Getting Started – Setting Up the Documentation Pipeline

If you're setting this up for the first time, follow these steps.

### Step 1: Add the Generator Script

1.  In the root of your repository, create a folder named `scripts/`.
2.  Add `generate-claude-docs.php` inside it (copy from this repo).

**What this script actually does** (all of it — this is the only place the
logic lives):

- Recursively scans `src/` for `.php` and `.js` files, extracting PHPDoc/JSDoc
  comment blocks, function signatures, constants, pillar definitions, and
  WordPress hooks.
- Writes `api.json` (machine-readable) and `api-reference.md` (plain markdown).
- Generates **`api-reference.html`** — a searchable, sidebar-navigated
  interactive reference page.
- Recursively scans `docs/` for `.md` files (skipping excluded ones — see
  above), converts each to a styled HTML page, including real markdown table
  support and Mermaid diagram rendering.
- Generates `index.html` — a portal page linking every generated doc.
- **Applies the real brand system** — the same obsidian/champagne palette,
  Cormorant Garamond/Plus Jakarta Sans/IBM Plex Mono type stack used in
  `src/frontend/index-frontend-styles.css` — embedded directly in the
  generated pages so the doc site and the live product read as one system.

### Step 2: Add the Workflow

1.  Add `.github/workflows/claude-doc.yml` (copy from this repo).
2.  It checks out the repo, installs PHP, runs
    `php scripts/generate-claude-docs.php ./src ./docs ./docs-site`, runs the
    excluded-content safety check, then deploys `./docs-site` to Pages.

| Section | Purpose |
|---------|---------|
| **Trigger (`on`)** | Runs on push to `main`. Can also be triggered manually via the Actions UI. |
| **Setup PHP** | Installs PHP 8.2 via `shivammathur/setup-php`. |
| **Build Documentation Site** | The one line that runs the actual generator — everything else is orchestration. |
| **Fail loudly if excluded content leaked** | Independent grep-based check; aborts the deploy (doesn't just warn) if excluded content somehow reached the output directory. |
| **Deploy to Pages** | Uses `actions/deploy-pages@v4`. |

### Step 3: Configure GitHub Pages to Use Actions as the Source

1.  Repository → **Settings** → **Pages**.
2.  Under **"Build and deployment" → "Source"**, select **GitHub Actions**.

> If this isn't set to Actions, the workflow will still run successfully but
> Pages won't serve its output — the site will look stale or 404.

### Step 4: Test Before Trusting It

Because this touches what gets published, verify a change before assuming
it's safe:

1.  Run `php scripts/generate-gemini-docs.php ./src ./docs ./docs-site`
    **locally** first. This now genuinely reproduces what deploys — v2.0
    couldn't, since the real logic only lived in the YAML.
2.  Check `docs-site/docs_manifest.json` — confirm nothing excluded is in
    there.
3.  `grep -rl "Paid Tier" docs-site/` (or whatever string is specific to your
    current excluded content) — should return nothing.
4.  Only then push, or trigger the workflow manually from the Actions tab to
    test without pushing.

### Step 5: View the Generated Documentation

- **Live site:** [https://blomstraventures.github.io/blomstra-insights/](https://blomstraventures.github.io/blomstra-insights/)
- **Build artifacts (debugging):** Actions tab → the specific run → Artifacts
  section → download the Pages artifact to inspect files locally without
  waiting for deployment.

---

## 📂 Adding New Documentation

| Type | Action |
|------|--------|
| **New public architecture guide** | Add a `.md` file to `docs/`. Picked up automatically, added to the portal grid. |
| **New internal-only doc** | Put it in `docs/internal/`, or add its filename to `EXCLUDED_DOC_FILES` in the script. Either way, confirm it via Step 4 above before pushing. |
| **New code function** | Standard PHPDoc/JSDoc comments above the function in `src/` — parsed automatically. |
| **Style changes** | Edit `src/frontend/index-frontend-styles.css`. The doc generator's embedded style block currently mirrors these tokens by hand — if you change the source stylesheet, update `biwStyleBlock()` in the script to match, since it's not a live import. |

---

## 🎨 Visual Features & Styling

- **Real brand alignment** — obsidian/champagne palette, matching type stack,
  actually embedded in generated pages (previously claimed, not implemented —
  fixed in v3.0).
- **Genuine markdown table rendering** — previously, real `| --- |` tables in
  the source docs rendered as broken plain-text paragraphs; fixed in v3.0.
- **Mermaid.js diagrams** — any ` ```mermaid ` code block renders as an SVG.
- **Back‑to‑top button** and **live function search** on the API reference page.

---

## ✅ What changed in v3.0

| Area | Before | Now |
|------|--------|-----|
| **Where the logic lives** | Split — simple parser in `scripts/`, real HTML/portal generator duplicated inline in the YAML, out of sync with each other | One file: `scripts/generate-gemini-docs.php` |
| **Testing locally** | Running the script didn't reproduce the deployed site | It does |
| **Sensitive content** | No exclusion mechanism — every `.md` under `docs/` was published, including internal roadmap/bug-tracking docs | Excluded by filename or folder, plus an independent CI check that aborts the deploy if excluded content is detected anyway |
| **Branding** | Claimed to match `index-frontend-styles.css`; actually hardcoded an unrelated slate/emerald theme | Actually matches |
| **Markdown tables** | Rendered as broken plain text | Rendered as real `<table>` HTML |
