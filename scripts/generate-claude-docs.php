<?php
/**
 * Blomstra Insights — Documentation Generator v3.0
 *
 * SINGLE SOURCE OF TRUTH for the doc pipeline. Previously this logic
 * was duplicated: this file only produced api.json/api-reference.md,
 * while the actual deployed HTML site (sidebar, search, portal,
 * markdown rendering) lived as an inline ~280-line PHP block inside
 * .github/workflows/gemini-doc.yml — untestable, unlintable, and out
 * of sync with what DOCUMENTATION-SETUP.md claimed it did.
 *
 * v3.0 merges both into one file the workflow simply calls. Nothing
 * about the code-parsing engine below changed from v2.0 — that part
 * already worked, so it's untouched on purpose. What's new:
 *   1. The actual HTML/portal generation (ported from the YAML, not
 *      rewritten from scratch, to minimize behavior drift)
 *   2. Exclusion filtering — see EXCLUDED_DOC_FILES / EXCLUDED_DOC_DIRS
 *      below. deviations.md and anything under docs/internal/ never
 *      reach the public site.
 *   3. Real brand styling — .biw obsidian/champagne tokens, matching
 *      src/frontend/index-frontend-styles.css, instead of the
 *      generic slate/emerald theme the old inline version hardcoded.
 *
 * @package Blomstra\Insights\Docs
 * @since   1.0.0
 * @version 3.0.0
 */

// ─── CONFIG ─────────────────────────────────────────────────────────

$srcDir  = $argv[1] ?? './src';
$docsDir = $argv[2] ?? './docs';
$outDir  = $argv[3] ?? './docs-site';

// Doc files that must NEVER be published, by exact basename, anywhere
// under $docsDir. Add to this list rather than deleting the file from
// the repo — internal roadmap/bug-tracking docs still belong in git,
// they just don't belong on a public GitHub Pages site.
const EXCLUDED_DOC_FILES = [
    'deviations.md',
];

// Any doc whose path contains one of these directory segments is
// excluded wholesale. Drop future internal-only docs in docs/internal/
// and they're excluded automatically — no script edit needed.
const EXCLUDED_DOC_DIRS = [
    'internal',
];

if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

function isExcludedDoc(string $relativePath): bool
{
    $basename = basename($relativePath);
    if (in_array($basename, EXCLUDED_DOC_FILES, true)) {
        return true;
    }
    $segments = explode(DIRECTORY_SEPARATOR, str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    foreach ($segments as $seg) {
        if (in_array($seg, EXCLUDED_DOC_DIRS, true)) {
            return true;
        }
    }
    return false;
}

// ─── SHARED BIW STYLE BLOCK (embedded once, reused by every page) ──
// Matches src/frontend/index-frontend-styles.css tokens exactly, so
// the doc site and the live product read as the same system.

function biwStyleBlock(): string
{
    return <<<CSS
<style>
* { box-sizing: border-box; }
body {
    margin: 0;
    background: #05060a;
    font-family: 'Plus Jakarta Sans', -apple-system, sans-serif;
    color: #E6DFD5;
}
:root {
    --biw-obsidian: #0A0C10;
    --biw-obsidian-card: #12151C;
    --biw-obsidian-elevated: #1A1E28;
    --biw-obsidian-border: #232833;
    --biw-champagne-light: #E6DFD5;
    --biw-champagne: #C5A880;
    --biw-champagne-dim: #8C7A63;
    --biw-slate: #B8BEC9;
    --biw-slate-dim: #6B7280;
    --biw-serif: 'Cormorant Garamond', Georgia, serif;
    --biw-sans: 'Plus Jakarta Sans', -apple-system, sans-serif;
    --biw-mono: 'IBM Plex Mono', 'Courier New', monospace;
}
a { color: var(--biw-champagne); }
code { background: var(--biw-obsidian-elevated); padding: 2px 6px; border-radius: 4px; color: var(--biw-champagne-light); font-family: var(--biw-mono); font-size: 0.9em; }
pre { background: var(--biw-obsidian-elevated); padding: 1.1rem; border-radius: 8px; overflow-x: auto; border: 1px solid var(--biw-obsidian-border); }
pre code { background: transparent; padding: 0; }
table { width: 100%; border-collapse: collapse; margin: 1.5rem 0; }
th { text-align: left; padding: 10px 14px; font-family: var(--biw-mono); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--biw-slate); border-bottom: 1px solid var(--biw-slate-dim); }
td { padding: 10px 14px; border-bottom: 1px solid rgba(35,40,51,0.4); }
blockquote { border-left: 3px solid var(--biw-champagne); padding-left: 1rem; margin: 1.2rem 0; color: var(--biw-slate); }
#back-to-top { position: fixed; bottom: 2rem; right: 2rem; background: var(--biw-champagne); color: #05060a; border: none; border-radius: 50%; width: 44px; height: 44px; font-size: 1.3rem; font-weight: bold; cursor: pointer; display: none; z-index: 1000; box-shadow: 0 4px 16px rgba(0,0,0,0.5); }
#back-to-top:hover { background: var(--biw-champagne-light); }
.doc-badge { display: inline-block; padding: 2px 10px; font-size: 0.62rem; border-radius: 20px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; margin-left: 6px; font-family: var(--biw-mono); }
.doc-badge-php { background: rgba(197,168,128,0.12); color: var(--biw-champagne); }
.doc-badge-js { background: rgba(184,190,201,0.12); color: var(--biw-slate); }
</style>
CSS;
}

function backToTopScript(): string
{
    return <<<JS
<script>
window.onscroll = function() {
    var btn = document.getElementById('back-to-top');
    if (btn) btn.style.display = (document.documentElement.scrollTop > 300) ? 'block' : 'none';
};
function scrollToTop() { window.scrollTo({top: 0, behavior: 'smooth'}); }
document.addEventListener('DOMContentLoaded', function() {
    if (window.mermaid) mermaid.initialize({ startOnLoad: true, theme: 'dark' });
});
</script>
JS;
}

// ─── CODE PARSING ENGINE (unchanged from v2.0 — this part worked) ──

function extractDocComment(string $content, int $pos): ?string
{
    $start = strrpos(substr($content, 0, $pos), '/**');
    if ($start === false) return null;
    $end = strpos($content, '*/', $start);
    if ($end === false || $end < $start) return null;
    return trim(preg_replace('/^\s*\*\s?/m', '', substr($content, $start + 3, $end - $start - 3)));
}

function parseCodeFile(string $path, string $type): array
{
    $content  = file_get_contents($path);
    $basename = basename($path);
    $ext      = pathinfo($path, PATHINFO_EXTENSION);
    $fileDoc  = [];

    if (preg_match('/^\s*\/\*\*(.*?)\*\//s', $content, $m)) {
        $fileDoc['description'] = trim(preg_replace('/^\s*\*\s?/m', '', $m[1]));
    }

    $constants = [];
    $functions = [];
    $pillars   = [];
    $hooks     = [];

    if ($ext === 'php') {
        preg_match_all("/define\(\s*['\"](\w+)['\"]\s*,\s*['\"]?(.*?)['\"]?\s*\)/", $content, $cm, PREG_SET_ORDER);
        foreach ($cm as $c) {
            $constants[] = ['name' => $c[1], 'value' => trim($c[2], "'\"")];
        }

        preg_match_all('/function\s+(\w+)\s*\((.*?)\)/s', $content, $fm, PREG_OFFSET_CAPTURE);
        foreach ($fm[0] as $i => $match) {
            $name   = $fm[1][$i][0];
            $params = $fm[2][$i][0];
            $pos    = $fm[0][$i][1];
            $doc    = extractDocComment($content, $pos);
            $functions[] = ['name' => $name, 'params' => trim($params), 'doc' => $doc];
        }

        if (preg_match_all("/'(\w+)'\s*=>\s*array\(\s*'name'\s*=>\s*['\"](.*?)['\"]/", $content, $pm)) {
            foreach ($pm[1] as $i => $key) {
                $pillars[] = ['key' => $key, 'name' => $pm[2][$i]];
            }
        }

        preg_match_all("/add_action\(\s*['\"]([\w_]+)['\"]/", $content, $hm);
        foreach ($hm[1] as $h) { $hooks[] = $h; }

    } elseif ($ext === 'js') {
        preg_match_all('/(?:async\s+)?function\s+(\w+)\s*\((.*?)\)/s', $content, $jm1, PREG_OFFSET_CAPTURE);
        foreach ($jm1[0] as $i => $match) {
            $functions[] = ['name' => $jm1[1][$i][0], 'params' => trim($jm1[2][$i][0]), 'doc' => extractDocComment($content, $jm1[0][$i][1])];
        }
        preg_match_all('/(?:const|let|var)\s+(\w+)\s*=\s*(?:async\s*)?\((.*?)\)\s*=>/s', $content, $jm2, PREG_OFFSET_CAPTURE);
        foreach ($jm2[0] as $i => $match) {
            $functions[] = ['name' => $jm2[1][$i][0], 'params' => trim($jm2[2][$i][0]), 'doc' => extractDocComment($content, $jm2[0][$i][1])];
        }
    }

    return [
        'file'        => $basename,
        'extension'   => $ext,
        'type'        => $type,
        'description' => $fileDoc['description'] ?? null,
        'constants'   => $constants,
        'functions'   => $functions,
        'pillars'     => $pillars,
        'hooks'       => array_unique($hooks),
    ];
}

$docs = [
    'meta' => [
        'generated' => date('c'),
        'generator' => 'blomstra-doc-generator',
        'version'   => '3.0.0',
    ],
    'indices'  => [],
    'shared'   => [],
    'frontend' => [],
];

if (is_dir($srcDir)) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
    foreach ($rii as $file) {
        if ($file->isDir()) continue;
        $ext = $file->getExtension();
        if (!in_array($ext, ['php', 'js'])) continue;

        $path = $file->getPathname();
        $rel  = str_replace($srcDir . '/', '', $path);
        $type = str_contains($rel, 'shared') ? 'shared' : (str_contains($rel, 'frontend') ? 'frontend' : 'indices');

        $parsed = parseCodeFile($path, $type);
        $docs[$type === 'indices' ? 'indices' : $type][] = $parsed;
    }
}

file_put_contents("$outDir/api.json", json_encode($docs, JSON_PRETTY_PRINT));

// Markdown reference (kept — this output already worked, some
// consumers may fetch it directly for a machine-readable summary)
$md = "# Blomstra Insights — Full Stack API Reference\n\n";
$md .= "> Auto-generated from PHP & JS source code on " . date('Y-m-d H:i') . " UTC\n\n";
foreach (['indices' => 'Indices (PHP Backend)', 'shared' => 'Shared Utilities (PHP)', 'frontend' => 'Frontend Engine (JavaScript)'] as $key => $heading) {
    $md .= "## $heading\n\n";
    foreach ($docs[$key] as $f) {
        $md .= "### `{$f['file']}`\n\n";
        if (!empty($f['description'])) $md .= "**Description:** {$f['description']}\n\n";
        foreach ($f['functions'] as $fn) {
            $md .= "#### `{$fn['name']}({$fn['params']})`\n";
            if ($fn['doc']) $md .= "```\n{$fn['doc']}\n```\n";
            $md .= "\n";
        }
    }
}
file_put_contents("$outDir/api-reference.md", $md);

// ─── HTML: api-reference.html (interactive, searchable) ────────────

function renderApiReferenceHtml(array $docs, string $outDir): void
{
    $sections = ['indices' => 'Indices', 'shared' => 'Shared Utilities', 'frontend' => 'Frontend Engine'];

    $sidebar = '';
    $main = '';
    foreach ($sections as $key => $label) {
        if (empty($docs[$key])) continue;
        $sidebar .= '<div class="nav-section"><div class="nav-title">' . $label . '</div>';
        foreach ($docs[$key] as $f) {
            $anchor = 'file-' . preg_replace('/[^a-z0-9]+/i', '-', $f['file']);
            $sidebar .= '<a class="nav-item" href="#' . $anchor . '">' . htmlspecialchars($f['file']) . '</a>';
        }
        $sidebar .= '</div>';

        foreach ($docs[$key] as $f) {
            $anchor = 'file-' . preg_replace('/[^a-z0-9]+/i', '-', $f['file']);
            $badgeClass = $f['extension'] === 'js' ? 'doc-badge-js' : 'doc-badge-php';
            $main .= '<div class="file-card" id="' . $anchor . '"><h2>' . htmlspecialchars($f['file']) . '<span class="doc-badge ' . $badgeClass . '">' . strtoupper($f['extension']) . '</span></h2>';
            if (!empty($f['description'])) {
                $main .= '<p class="file-desc">' . nl2br(htmlspecialchars($f['description'])) . '</p>';
            }
            if (!empty($f['pillars'])) {
                $main .= '<div class="pillar-list"><strong>Pillars:</strong> ';
                foreach ($f['pillars'] as $p) {
                    $main .= '<code>' . htmlspecialchars($p['key']) . '</code> ';
                }
                $main .= '</div>';
            }
            foreach ($f['functions'] as $fn) {
                $main .= '<div class="fn-card"><div class="fn-name">' . htmlspecialchars($fn['name']) . '(' . htmlspecialchars($fn['params']) . ')</div>';
                if ($fn['doc']) $main .= '<div class="fn-doc">' . nl2br(htmlspecialchars($fn['doc'])) . '</div>';
                $main .= '</div>';
            }
            $main .= '</div>';
        }
    }

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    $html .= '<title>Blomstra Code API Reference</title>';
    $html .= '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Plus+Jakarta+Sans:wght@300;400;500&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">';
    $html .= biwStyleBlock();
    $html .= '<style>
        body { display: flex; min-height: 100vh; }
        .sidebar { width: 300px; background: var(--biw-obsidian-card); border-right: 1px solid var(--biw-obsidian-border); position: sticky; top: 0; height: 100vh; overflow-y: auto; padding: 1.5rem 1.2rem; flex-shrink: 0; }
        .top-link { display: block; color: var(--biw-champagne); text-decoration: none; font-weight: 600; margin-bottom: 1.2rem; font-family: var(--biw-mono); font-size: 0.78rem; }
        .search-box { width: 100%; padding: 0.6rem 0.8rem; background: var(--biw-obsidian); border: 1px solid var(--biw-obsidian-border); border-radius: 6px; color: var(--biw-champagne-light); margin-bottom: 1.4rem; font-family: var(--biw-sans); }
        .nav-section { margin-bottom: 1.3rem; }
        .nav-title { font-family: var(--biw-mono); font-size: 0.65rem; text-transform: uppercase; color: var(--biw-champagne-dim); letter-spacing: 0.06em; font-weight: 600; margin-bottom: 0.4rem; }
        .nav-item { display: block; padding: 0.35rem 0.5rem; color: var(--biw-slate); text-decoration: none; border-radius: 4px; font-size: 0.85rem; font-family: var(--biw-mono); }
        .nav-item:hover { background: var(--biw-obsidian-elevated); color: var(--biw-champagne-light); }
        .main { flex: 1; padding: 3rem 4rem; max-width: 1000px; }
        h1 { font-family: var(--biw-serif); font-weight: 500; color: #fff; font-size: 2.2rem; margin-bottom: 0.4rem; }
        .sub { color: var(--biw-slate); margin-bottom: 2.4rem; border-bottom: 1px solid var(--biw-obsidian-border); padding-bottom: 1rem; font-size: 0.9rem; }
        .file-card { background: var(--biw-obsidian-card); border: 1px solid var(--biw-obsidian-border); border-radius: 8px; padding: 1.5rem; margin-bottom: 2rem; }
        .file-card h2 { font-family: var(--biw-serif); font-weight: 500; color: var(--biw-champagne-light); font-size: 1.3rem; margin-bottom: 0.8rem; }
        .file-desc { color: var(--biw-slate); font-size: 0.85rem; white-space: pre-wrap; margin-bottom: 1rem; }
        .pillar-list { font-size: 0.82rem; color: var(--biw-slate); margin-bottom: 1rem; }
        .fn-card { background: var(--biw-obsidian); border-left: 3px solid var(--biw-champagne); padding: 0.9rem 1rem; border-radius: 4px; margin-bottom: 0.9rem; }
        .fn-name { font-family: var(--biw-mono); color: var(--biw-champagne); font-size: 0.95rem; }
        .fn-doc { color: var(--biw-slate); font-size: 0.82rem; margin-top: 0.5rem; white-space: pre-wrap; }
        .file-card[data-hidden="true"], .nav-item[data-hidden="true"] { display: none; }
        @media (max-width: 900px) { body { flex-direction: column; } .sidebar { position: static; height: auto; width: 100%; } .main { padding: 2rem 1.5rem; } }
    </style></head><body>';
    $html .= '<button id="back-to-top" onclick="scrollToTop()">&uarr;</button>';
    $html .= '<div class="sidebar"><a class="top-link" href="index.html">&larr; Back to Portal</a>';
    $html .= '<input type="text" class="search-box" id="fn-search" placeholder="Search functions…">';
    $html .= $sidebar . '</div>';
    $html .= '<div class="main"><h1>Code API Reference</h1><p class="sub">Auto-extracted from PHPDoc/JSDoc comments in src/ — regenerated on every push.</p>' . $main . '</div>';
    $html .= backToTopScript();
    $html .= '<script>
        document.getElementById("fn-search").addEventListener("input", function(e) {
            var q = e.target.value.toLowerCase();
            document.querySelectorAll(".fn-card").forEach(function(card) {
                var match = card.textContent.toLowerCase().includes(q);
                card.style.display = match ? "" : "none";
            });
        });
    </script>';
    $html .= '</body></html>';

    file_put_contents("$outDir/api-reference.html", $html);
}

renderApiReferenceHtml($docs, $outDir);

// ─── HTML: architecture/methodology docs from docs/*.md ────────────

function renderMarkdown(string $text): string
{
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    $text = preg_replace_callback('/```(\w*)\n(.*?)```/s', function ($m) {
        $lang = strtolower(trim($m[1] ?? ''));
        if ($lang === 'mermaid') {
            return '<div class="mermaid">' . html_entity_decode($m[2]) . '</div>';
        }
        return '<pre><code class="language-' . ($lang ?: 'text') . '">' . $m[2] . '</code></pre>';
    }, $text);

    // Real markdown tables: a header row, a |---|---| separator row,
    // then body rows. Verified against the actual docs before adding
    // this — 10+ genuine tables across the doc set were previously
    // falling through to plain paragraph text with literal pipes and
    // dashes, since the original renderer never handled this syntax.
    // Runs line-by-line, before the block-split step below, so a
    // detected table block is protected from being re-wrapped in <p>.
    $lines = explode("\n", $text);
    $out = [];
    $i = 0;
    $n = count($lines);
    while ($i < $n) {
        $line = $lines[$i];
        $isHeaderRow = (bool) preg_match('/^\s*\|(.+)\|\s*$/', $line);
        $nextIsSeparator = $isHeaderRow && $i + 1 < $n && preg_match('/^\s*\|[\s:\-|]+\|\s*$/', $lines[$i + 1]);

        if ($nextIsSeparator) {
            $headerCells = array_map('trim', explode('|', trim(trim($line), '|')));
            $tableHtml = "<table><thead><tr>";
            foreach ($headerCells as $c) {
                $tableHtml .= '<th>' . $c . '</th>';
            }
            $tableHtml .= "</tr></thead><tbody>";
            $i += 2; // skip header + separator
            while ($i < $n && preg_match('/^\s*\|(.+)\|\s*$/', $lines[$i])) {
                $rowCells = array_map('trim', explode('|', trim(trim($lines[$i]), '|')));
                $tableHtml .= '<tr>';
                foreach ($rowCells as $c) {
                    $tableHtml .= '<td>' . $c . '</td>';
                }
                $tableHtml .= '</tr>';
                $i++;
            }
            $tableHtml .= "</tbody></table>";
            $out[] = $tableHtml;
            continue;
        }

        $out[] = $line;
        $i++;
    }
    $text = implode("\n", $out);

    $text = preg_replace('/^#### (.*$)/m', '<h4>$1</h4>', $text);
    $text = preg_replace('/^### (.*$)/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^## (.*$)/m', '<h2>$1</h2>', $text);
    $text = preg_replace('/^# (.*$)/m', '<h1>$1</h1>', $text);
    $text = preg_replace('/^&gt;\s*(.*$)/m', '<blockquote>$1</blockquote>', $text);
    $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

    $blocks = explode("\n\n", $text);
    $out = [];
    foreach ($blocks as $b) {
        $b = trim($b);
        if ($b === '') continue;
        if (preg_match('/^<(h[1-6]|pre|blockquote|div|table)/i', $b)) {
            $out[] = $b;
        } else {
            $out[] = '<p>' . nl2br($b) . '</p>';
        }
    }
    return implode("\n\n", $out);
}

function docPageTemplate(string $title, string $body): string
{
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    $html .= '<title>' . htmlspecialchars($title) . ' — Blomstra Insights</title>';
    $html .= '<script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script>';
    $html .= '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Plus+Jakarta+Sans:wght@300;400;500&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">';
    $html .= biwStyleBlock();
    $html .= '<style>
        body { line-height: 1.7; max-width: 900px; margin: 0 auto; padding: 2.5rem 1.5rem 5rem; }
        header { padding-bottom: 1.4rem; margin-bottom: 2rem; border-bottom: 1px solid var(--biw-obsidian-border); display: flex; justify-content: space-between; align-items: center; font-family: var(--biw-mono); font-size: 0.8rem; }
        header a { text-decoration: none; }
        h1, h2, h3, h4 { font-family: var(--biw-serif); font-weight: 500; color: #fff; margin-top: 1.8rem; margin-bottom: 0.7rem; }
        h1 { font-size: 2.1rem; border-bottom: 1px solid var(--biw-obsidian-border); padding-bottom: 0.5rem; }
        p, li { color: var(--biw-slate); margin-bottom: 1rem; }
        li { margin-left: 0.5rem; }
    </style></head><body>';
    $html .= '<button id="back-to-top" onclick="scrollToTop()">&uarr;</button>';
    $html .= '<header><a href="index.html">&larr; Back to Portal</a><a href="api-reference.html">Code API &rarr;</a></header>';
    $html .= '<main>' . $body . '</main>';
    $html .= backToTopScript();
    $html .= '</body></html>';
    return $html;
}

$manifest = [];
if (is_dir($docsDir)) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docsDir));
    foreach ($rii as $file) {
        if ($file->isDir() || strtolower($file->getExtension()) !== 'md') continue;

        $pathName = $file->getPathname();
        $relative = ltrim(str_replace($docsDir, '', $pathName), '/\\');

        if (isExcludedDoc($relative)) {
            echo "Skipping excluded doc: $relative\n";
            continue;
        }

        // Flatten nested paths (docs/api/indices/seri.md) into a safe,
        // unique output filename so nothing collides or 404s.
        $slug = preg_replace('/[^a-z0-9\-]+/i', '-', pathinfo($relative, PATHINFO_FILENAME) . '-' . dirname($relative));
        $slug = trim(preg_replace('/-+/', '-', strtolower($slug)), '-');
        $basename = basename($pathName, '.md') === $slug ? $slug : $slug;

        $rawMd = file_get_contents($pathName);
        $title = ucfirst(str_replace(['-', '_'], ' ', basename($pathName, '.md')));
        if (preg_match('/^#\s+(.+)$/m', $rawMd, $m)) {
            $title = trim($m[1]);
        }

        $body = renderMarkdown($rawMd);
        file_put_contents("$outDir/$slug.html", docPageTemplate($title, $body));

        $manifest[] = [
            'file'   => "$slug.html",
            'title'  => $title,
            'source' => $relative,
        ];
    }
}

usort($manifest, fn($a, $b) => strcmp($a['title'], $b['title']));
file_put_contents("$outDir/docs_manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));

// ─── HTML: portal / index.html ──────────────────────────────────────

$portalHtml = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
$portalHtml .= '<title>Blomstra Insights — Developer Portal</title>';
$portalHtml .= '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Plus+Jakarta+Sans:wght@300;400;500&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">';
$portalHtml .= biwStyleBlock();
$portalHtml .= '<style>
    .hero { text-align: center; padding: 4rem 1.5rem; background: var(--biw-obsidian-card); border-bottom: 1px solid var(--biw-obsidian-border); }
    .hero h1 { font-family: var(--biw-serif); font-weight: 500; color: #fff; font-size: 2.4rem; margin-bottom: 0.5rem; }
    .hero p { color: var(--biw-slate); font-size: 1.05rem; }
    .container { max-width: 1000px; margin: 3rem auto; padding: 0 1.5rem; }
    .section-title { font-family: var(--biw-mono); color: var(--biw-champagne-dim); font-size: 0.75rem; margin-bottom: 1.2rem; text-transform: uppercase; letter-spacing: 0.06em; border-bottom: 1px solid var(--biw-obsidian-border); padding-bottom: 0.5rem; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.1rem; margin-bottom: 3rem; }
    .card { background: var(--biw-obsidian-card); border: 1px solid var(--biw-obsidian-border); border-radius: 10px; padding: 1.4rem; text-decoration: none; color: inherit; transition: transform 0.2s, border-color 0.2s; display: block; }
    .card:hover { transform: translateY(-3px); border-color: var(--biw-champagne); }
    .card h3 { font-family: var(--biw-serif); font-weight: 500; color: var(--biw-champagne-light); margin-bottom: 0.4rem; font-size: 1.15rem; }
    .card p { color: var(--biw-slate); font-size: 0.85rem; }
    .badge { display: inline-block; margin-top: 0.8rem; padding: 2px 10px; border-radius: 20px; font-size: 0.62rem; font-weight: 600; font-family: var(--biw-mono); text-transform: uppercase; letter-spacing: 0.04em; background: rgba(197,168,128,0.12); color: var(--biw-champagne); }
</style></head><body>';
$portalHtml .= '<div class="hero"><h1>Blomstra Insights</h1><p>Developer documentation &amp; code API reference</p></div>';
$portalHtml .= '<div class="container">';
$portalHtml .= '<div class="section-title">Developer Code API</div><div class="grid">';
$portalHtml .= '<a class="card" href="api-reference.html"><h3>Interactive Code API Reference</h3><p>Parsed PHP/JS functions, docblocks, parameters, and hooks with live search.</p><span class="badge">Auto-Generated</span></a>';
$portalHtml .= '<a class="card" href="api.json" download><h3>Machine API JSON Schema</h3><p>Full structured JSON of all parsed classes and functions.</p><span class="badge">Raw Metadata</span></a>';
$portalHtml .= '</div>';

if (!empty($manifest)) {
    $portalHtml .= '<div class="section-title">Architecture &amp; Methodology Guides</div><div class="grid">';
    foreach ($manifest as $doc) {
        $portalHtml .= '<a class="card" href="' . htmlspecialchars($doc['file']) . '"><h3>' . htmlspecialchars($doc['title']) . '</h3><p>From docs/' . htmlspecialchars($doc['source']) . '</p><span class="badge">Architecture Doc</span></a>';
    }
    $portalHtml .= '</div>';
}
$portalHtml .= '</div></body></html>';

file_put_contents("$outDir/index.html", $portalHtml);

echo "Done. " . count($docs['indices']) . " index files, " . count($docs['shared']) . " shared files, "
    . count($docs['frontend']) . " frontend files parsed. " . count($manifest) . " doc pages generated.\n";
