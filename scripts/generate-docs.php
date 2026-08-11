<?php
/**
 * Blomstra Insights — Auto-Doc Parser
 * Scans PHP snippets for functions, constants, pillars, and weights.
 * Outputs Markdown + JSON. No WordPress required.
 */

$srcDir = $argv[1] ?? './src';
$outDir = $argv[2] ?? './docs-site';

if (!is_dir($outDir)) mkdir($outDir, 0777, true);

$docs = [
    'meta' => [
        'generated' => date('c'),
        'generator' => 'blomstra-auto-doc-parser',
        'version' => '1.0.0',
    ],
    'indices' => [],
    'shared' => [],
    'frontend' => [],
];

function extractDocComment(string $content, int $pos): ?string {
    // Walk backwards to find /**
    $start = strrpos(substr($content, 0, $pos), '/**');
    if ($start === false) return null;
    $end = strpos($content, '*/', $start);
    if ($end === false) return null;
    return trim(preg_replace('/^\s*\*\s?/m', '', substr($content, $start + 3, $end - $start - 3)));
}

function parseFile(string $path, string $type): array {
    $content = file_get_contents($path);
    $basename = basename($path);
    $fileDoc = [];

    // File-level docblock
    if (preg_match('/^\s*\/\*\*(.*?)\*\//s', $content, $m)) {
        $fileDoc['description'] = trim(preg_replace('/^\s*\*\s?/m', '', $m[1]));
    }

    // Constants
    $constants = [];
    preg_match_all("/define\(\s*['\"](\w+)['\"]\s*,\s*['\"]?(.*?)['\"]?\s*\)/", $content, $cm, PREG_SET_ORDER);
    foreach ($cm as $c) {
        $constants[] = ['name' => $c[1], 'value' => trim($c[2], "'\"")];
    }

    // Functions with docblocks
    $functions = [];
    preg_match_all('/function\s+(\w+)\s*\((.*?)\)/s', $content, $fm, PREG_OFFSET_CAPTURE);
    foreach ($fm[0] as $i => $match) {
        $name = $fm[1][$i][0];
        $params = $fm[2][$i][0];
        $pos = $fm[0][$i][1];
        $doc = extractDocComment($content, $pos);
        $functions[] = [
            'name' => $name,
            'params' => $params,
            'doc' => $doc,
        ];
    }

    // Pillar definitions (Blomstra-specific)
    $pillars = [];
    if (preg_match_all("/(\w+)_get_pillar_weights\s*\(\s*\)/", $content, $wm)) {
        // Try to find the return array
        preg_match_all("/'(\w+)'\s*=>\s*array\(\s*'name'\s*=>\s*['\"](.*?)['\"]/", $content, $pm);
        foreach ($pm[1] as $i => $key) {
            $pillars[] = ['key' => $key, 'name' => $pm[2][$i]];
        }
    }

    // Async hooks
    $hooks = [];
    preg_match_all("/add_action\(\s*['\"]([\w_]+)['\"]/", $content, $hm);
    foreach ($hm[1] as $h) {
        $hooks[] = $h;
    }

    return [
        'file' => $basename,
        'type' => $type,
        'description' => $fileDoc['description'] ?? null,
        'constants' => $constants,
        'functions' => $functions,
        'pillars' => $pillars,
        'hooks' => array_unique($hooks),
    ];
}

// Walk src/
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
foreach ($rii as $file) {
    if ($file->isDir()) continue;
    if ($file->getExtension() !== 'php') continue;
    $path = $file->getPathname();
    $rel = str_replace($srcDir . '/', '', $path);
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

// Write JSON API
file_put_contents("$outDir/api.json", json_encode($docs, JSON_PRETTY_PRINT));

// Generate Markdown index
$md = "# Blomstra Insights — API Reference\n\n";
$md .= "> Auto-generated from source code on " . date('Y-m-d H:i') . " UTC\n\n";
$md .= "## Indices\n\n";
foreach ($docs['indices'] as $idx) {
    $prefix = preg_replace('/-backend\.php$/', '', $idx['file']);
    $md .= "### " . strtoupper($prefix) . "\n\n";
    $md .= "- **File:** `{$idx['file']}`\n";
    if ($idx['description']) $md .= "- **Description:** {$idx['description']}\n";
    if ($idx['pillars']) {
        $md .= "- **Pillars:**\n";
        foreach ($idx['pillars'] as $p) {
            $md .= "  - `{$p['key']}` — {$p['name']}\n";
        }
    }
    if ($idx['constants']) {
        $md .= "- **Key Constants:**\n";
        foreach (array_slice($idx['constants'], 0, 5) as $c) {
            $md .= "  - `{$c['name']}` = `{$c['value']}`\n";
        }
    }
    if ($idx['hooks']) {
        $md .= "- **WordPress Hooks:** " . implode(', ', array_slice($idx['hooks'], 0, 5)) . "\n";
    }
    $md .= "- **Functions:** " . count($idx['functions']) . " defined\n";
    $md .= "\n<details><summary>View all functions</summary>\n\n";
    foreach ($idx['functions'] as $fn) {
        $md .= "#### `{$fn['name']}({$fn['params']})`\n\n";
        if ($fn['doc']) $md .= $fn['doc'] . "\n\n";
    }
    $md .= "</details>\n\n";
}

$md .= "## Shared Utilities\n\n";
foreach ($docs['shared'] as $sh) {
    $md .= "### `{$sh['file']}`\n\n";
    if ($sh['description']) $md .= $sh['description'] . "\n\n";
    $md .= "- **Functions:** " . count($sh['functions']) . "\n";
    $md .= "- **Constants:** " . count($sh['constants']) . "\n\n";
}

$md .= "## Frontend Engine\n\n";
foreach ($docs['frontend'] as $fe) {
    $md .= "### `{$fe['file']}`\n\n";
    if ($fe['description']) $md .= $fe['description'] . "\n\n";
}

file_put_contents("$outDir/api-reference.md", $md);

echo "Generated:\n";
echo "  - $outDir/api.json\n";
echo "  - $outDir/api-reference.md\n";
echo "  - " . count($docs['indices']) . " indices parsed\n";
echo "  - " . count($docs['shared']) . " shared files parsed\n";
