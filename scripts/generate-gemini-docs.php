<?php
/**
 * Blomstra Insights — Gemini Auto-Doc Parser v2.0
 * Scans PHP & JS files for functions, PHPDoc/JSDoc tags, constants, pillars, and hooks.
 * Generates api.json, api-reference.md, and converted HTML docs.
 */

$srcDir = $argv[1] ?? './src';
$outDir = $argv[2] ?? './docs-site';

if (!is_dir($outDir)) mkdir($outDir, 0777, true);

$docs = [
    'meta' => [
        'generated' => date('c'),
        'generator' => 'blomstra-gemini-doc-parser',
        'version'   => '2.0.0',
    ],
    'indices'  => [],
    'shared'   => [],
    'frontend' => [],
];

function extractDocComment(string $content, int $pos): ?string {
    $start = strrpos(substr($content, 0, $pos), '/**');
    if ($start === false) return null;
    $end = strpos($content, '*/', $start);
    if ($end === false || $end < $start) return null;
    return trim(preg_replace('/^\s*\*\s?/m', '', substr($content, $start + 3, $end - $start - 3)));
}

function parseFile(string $path, string $type): array {
    $content  = file_get_contents($path);
    $basename = basename($path);
    $ext      = pathinfo($path, PATHINFO_EXTENSION);
    $fileDoc  = [];

    // File-level docblock
    if (preg_match('/^\s*\/\*\*(.*?)\*\//s', $content, $m)) {
        $fileDoc['description'] = trim(preg_replace('/^\s*\*\s?/m', '', $m[1]));
    }

    $constants = [];
    $functions = [];
    $pillars   = [];
    $hooks     = [];

    if ($ext === 'php') {
        // PHP Constants
        preg_match_all("/define\(\s*['\"](\w+)['\"]\s*,\s*['\"]?(.*?)['\"]?\s*\)/", $content, $cm, PREG_SET_ORDER);
        foreach ($cm as $c) {
            $constants[] = ['name' => $c[1], 'value' => trim($c[2], "'\"")];
        }

        // PHP Functions with DocBlocks
        preg_match_all('/function\s+(\w+)\s*\((.*?)\)/s', $content, $fm, PREG_OFFSET_CAPTURE);
        foreach ($fm[0] as $i => $match) {
            $name   = $fm[1][$i][0];
            $params = $fm[2][$i][0];
            $pos    = $fm[0][$i][1];
            $doc    = extractDocComment($content, $pos);
            $functions[] = ['name' => $name, 'params' => trim($params), 'doc' => $doc];
        }

        // Blomstra Pillar Definitions
        if (preg_match_all("/'(\w+)'\s*=>\s*array\(\s*'name'\s*=>\s*['\"](.*?)['\"]/", $content, $pm)) {
            foreach ($pm[1] as $i => $key) {
                $pillars[] = ['key' => $key, 'name' => $pm[2][$i]];
            }
        }

        // WP Hooks
        preg_match_all("/add_action\(\s*['\"]([\w_]+)['\"]/", $content, $hm);
        foreach ($hm[1] as $h) { $hooks[] = $h; }

    } elseif ($ext === 'js') {
        // JavaScript Functions & JSDoc
        preg_match_all('/function\s+(\w+)\s*\((.*?)\)/s', $content, $jm, PREG_OFFSET_CAPTURE);
        foreach ($jm[0] as $i => $match) {
            $name   = $jm[1][$i][0];
            $params = $jm[2][$i][0];
            $pos    = $jm[0][$i][1];
            $doc    = extractDocComment($content, $pos);
            $functions[] = ['name' => $name, 'params' => trim($params), 'doc' => $doc];
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

// Walk src directory recursively
if (is_dir($srcDir)) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
    foreach ($rii as $file) {
        if ($file->isDir()) continue;
        $ext = $file->getExtension();
        if (!in_array($ext, ['php', 'js'])) continue;

        $path = $file->getPathname();
        $rel  = str_replace($srcDir . '/', '', $path);
        $type = str_contains($rel, 'shared') ? 'shared' : (str_contains($rel, 'frontend') ? 'frontend' : 'index');

        $parsed = parseFile($path, $type);
        if ($type === 'index') {
            $docs['indices'][] = $parsed;
        } elseif ($type === 'shared') {
            $docs['shared'][] = $parsed;
        } else {
            $docs['frontend'][] = $parsed;
        }
    }
}

// Write API JSON output
file_put_contents("$outDir/api.json", json_encode($docs, JSON_PRETTY_PRINT));

// Build Markdown API reference
$md = "# Blomstra Insights — Full Stack API Reference\n\n";
$md .= "> Auto-generated from PHP & JS source code on " . date('Y-m-d H:i') . " UTC\n\n";

$md .= "## Indices (PHP Backend)\n\n";
foreach ($docs['indices'] as $idx) {
    $md .= "### `{$idx['file']}`\n\n";
    if ($idx['description']) $md .= "**Description:** {$idx['description']}\n\n";
    if ($idx['pillars']) {
        $md .= "**Pillars Defined:**\n";
        foreach ($idx['pillars'] as $p) { $md .= "- `{$p['key']}` — {$p['name']}\n"; }
        $md .= "\n";
    }
    $md .= "**Functions (" . count($idx['functions']) . "):**\n\n";
    foreach ($idx['functions'] as $fn) {
        $md .= "#### `{$fn['name']}({$fn['params']})`\n";
        if ($fn['doc']) $md .= "```\n" . $fn['doc'] . "\n```\n";
        $md .= "\n";
    }
}

$md .= "## Shared Utilities (PHP)\n\n";
foreach ($docs['shared'] as $sh) {
    $md .= "### `{$sh['file']}`\n\n";
    foreach ($sh['functions'] as $fn) {
        $md .= "#### `{$fn['name']}({$fn['params']})`\n";
        if ($fn['doc']) $md .= "```\n" . $fn['doc'] . "\n```\n";
    }
}

$md .= "## Frontend Engine (JavaScript)\n\n";
foreach ($docs['frontend'] as $fe) {
    $md .= "### `{$fe['file']}`\n\n";
    foreach ($fe['functions'] as $fn) {
        $md .= "#### `{$fn['name']}({$fn['params']})`\n";
        if ($fn['doc']) $md .= "```\n" . $fn['doc'] . "\n```\n";
    }
}

file_put_contents("$outDir/api-reference.md", $md);
echo "Gemini Auto-Doc Parser execution finished successfully.\n";
