<?php

namespace Codeeshop\PsModuleOcmod;

class XMLModifier
{
    private $modDir;
    private $rootDir;

    public function __construct($rootDir, $modDir)
    {
        $this->rootDir = realpath($rootDir);
        $this->modDir = $modDir;
    }

    public function apply()
    {
        foreach (glob($this->modDir . '*/*/*.ocmod.xml') as $file) {
            $xml = simplexml_load_file($file);
            foreach ($xml->file as $f) {
                $relPath = (string) $f['path'];
                $fullPath = $this->rootDir . '/' . $relPath;
                $backupPath = $fullPath . '.bak';

                if (!file_exists($fullPath)) continue;

                $original = file_get_contents($fullPath);
                if (!file_exists($backupPath)) {
                    file_put_contents($backupPath, $original);
                }

                $modified = $original;

                foreach ($f->operation as $op) {
                    $searchNode = $op->search;
                    $addNode = $op->add;

                    $search = (string) $searchNode;
                    $add = (string) $addNode;

                    // Attributes
                    $searchTrim = (string) $searchNode['trim'] === 'true';
                    $addTrim = (string) $addNode['trim'] === 'true';
                    $position = (string) $addNode['position'] ?: 'replace';
                    $regex = (string) $searchNode['regex'] === 'true';
                    $limit = (int) $searchNode['limit'] ?: -1;
                    $offset = (int) $addNode['offset'] ?: 0;

                    if ($searchTrim) $search = trim($search);
                    if ($addTrim) $add = trim($add);

                    if ($regex) {
                        $pattern = '/' . $search . '/';
                        $count = 0;

                        $modified = preg_replace_callback($pattern, function ($matches) use ($add, &$count, $limit) {
                            if ($limit >= 0 && $count >= $limit) return $matches[0];
                            $count++;
                            return $add;
                        }, $modified);
                    } else {
                        // Non-regex mode: line-based modification
                        $index = isset($searchNode['index']) ? (int)$searchNode['index'] : -1;

                        $index = isset($searchNode['index']) ? (int)$searchNode['index'] : -1;

                        $lines = explode("\n", $modified);
                        $newLines = [];
                        $matchCount = 0;

                        for ($i = 0; $i < count($lines); $i++) {
                            $line = $lines[$i];
                            $check = $searchTrim ? trim($line) : $line;

                            if (strpos($check, $search) !== false) {
                                $shouldApply = ($index === -1 || $matchCount === $index);
                                $matchCount++;

                                if ($shouldApply) {
                                    switch ($position) {
                                        case 'before':
                                            $newLines[] = $add;
                                            $newLines[] = $line;
                                            break;

                                        case 'after':
                                            $newLines[] = $line;

                                            // Offset controls how many lines after the match we skip before injecting
                                            for ($j = 1; $j <= $offset && ($i + $j) < count($lines); $j++) {
                                                $newLines[] = $lines[$i + $j];
                                            }

                                            $newLines[] = $add;

                                            // Advance $i by offset lines, since we already added them
                                            $i += $offset;
                                            break;

                                        case 'replace':
                                            // Preserve indentation of the original line
                                            preg_match('/^(\s*)/', $line, $indentMatch);
                                            $indent = $indentMatch[1] ?? '';

                                            // Indent each line of the injected code
                                            $indentedAdd = implode("\n", array_map(function ($l) use ($indent) {
                                                return $indent . $l;
                                            }, explode("\n", $add)));

                                            $newLines[] = $indentedAdd;
                                            break;
                                    }
                                } else {
                                    $newLines[] = $line;
                                }
                            } else {
                                $newLines[] = $line;
                            }
                        }

                        $modified = implode("\n", $newLines);
                    }
                }

                file_put_contents($fullPath, $modified);
            }
        }

        return true;
    }

    public function revert()
    {
        foreach (glob($this->modDir . '*/*/*.ocmod.xml') as $file) {
            $xml = simplexml_load_file($file);
            foreach ($xml->file as $f) {
                $relPath = (string) $f['path'];
                $fullPath = $this->rootDir . '/' . $relPath;
                $backupPath = $fullPath . '.bak';

                if (file_exists($backupPath)) {
                    copy($backupPath, $fullPath);
                    unlink($backupPath);
                }
            }
        }

        return true;
    }
}
