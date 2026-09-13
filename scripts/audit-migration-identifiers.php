<?php

declare(strict_types=1);

function identifierName(string $table, array $columns, string $type = 'index'): string
{
    $parts = array_merge([$table], $columns, [$type]);

    return str_replace('.', '_', strtolower(implode('_', $parts)));
}

$base = dirname(__DIR__);
$files = glob($base.'/database/migrations/*.php') ?: [];
$issues = [];

foreach ($files as $file) {
    $content = file_get_contents($file);
    if ($content === false) {
        continue;
    }

    if (! preg_match_all('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*function\s*\([^)]*\)\s*(?::\s*\w+\s*)?\{([\s\S]*?)\n\s*\}\);/', $content, $creates, PREG_SET_ORDER)) {
        continue;
    }

    foreach ($creates as $create) {
        $table = $create[1];
        $block = $create[2];

        if (preg_match_all('/->index\(\s*\[([^\]]+)\]\s*(?:,\s*[\'"]([^\'"]+)[\'"]\s*)?\)/', $block, $indexes, PREG_SET_ORDER)) {
            foreach ($indexes as $index) {
                $cols = array_map(static fn (string $c): string => trim($c, " \t\n\r\0\x0B'\""), explode(',', $index[1]));
                $name = $index[2] ?? identifierName($table, $cols, 'index');
                if (strlen($name) > 64) {
                    $issues[] = ['file' => $file, 'table' => $table, 'name' => $name, 'kind' => 'index', 'cols' => $cols];
                }
            }
        }

        if (preg_match_all('/->unique\(\s*\[([^\]]+)\]\s*(?:,\s*[\'"]([^\'"]+)[\'"]\s*)?\)/', $block, $uniques, PREG_SET_ORDER)) {
            foreach ($uniques as $unique) {
                $cols = array_map(static fn (string $c): string => trim($c, " \t\n\r\0\x0B'\""), explode(',', $unique[1]));
                $name = $unique[2] ?? identifierName($table, $cols, 'unique');
                if (strlen($name) > 64) {
                    $issues[] = ['file' => $file, 'table' => $table, 'name' => $name, 'kind' => 'unique', 'cols' => $cols];
                }
            }
        }

        if (preg_match_all('/->foreignId\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $block, $fkCols, PREG_SET_ORDER)) {
            foreach ($fkCols as $fkCol) {
                $column = $fkCol[1];
                $name = identifierName($table, [$column], 'foreign');
                if (strlen($name) > 64) {
                    $issues[] = ['file' => $file, 'table' => $table, 'name' => $name, 'kind' => 'foreign', 'cols' => [$column]];
                }
            }
        }
    }
}

foreach ($issues as $issue) {
    echo basename($issue['file'])."\t".$issue['kind']."\t".$issue['table']."\t".strlen($issue['name'])."\t".$issue['name']."\t[".implode(',', $issue['cols'])."]\n";
}

echo 'TOTAL: '.count($issues)."\n";
