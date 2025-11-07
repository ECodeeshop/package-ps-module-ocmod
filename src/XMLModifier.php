<?php
namespace Codeeshop\PsModuleOcmod;

class XMLModifier
{
    private $modDir;
    private $rootDir;
    private $bakFiles = [];
    private static $active_modules = [];

    public function __construct($rootDir, $modDir)
    {
        $this->rootDir = realpath($rootDir);
        $this->modDir = $modDir;
    }

    public function apply()
    {
        foreach (glob($this->modDir . '*/*/*.ocmod.xml') as $file) {
            $module_name = basename(dirname(dirname($file)));
            if (!$this->isActiveModule($module_name)) {
                continue;
            }

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
                        $matchCount = 0;
                        $targetIndex = isset($searchNode['index']) ? (int)$searchNode['index'] : -1;

                        for ($line_id = 0; $line_id < count($lines); $line_id++) {
                            $line = $lines[$line_id];

                            if (strpos($line, $search) !== false) {
                                // Respect index="X"
                                if ($targetIndex !== -1 && $matchCount !== $targetIndex) {
                                    $matchCount++;
                                    continue;
                                }
                                $matchCount++;

                                switch ($position) {
                                    default:
                                    case 'replace':
                                        $new_lines = explode("\n", $add);

                                        if ($offset < 0) {
                                            array_splice($lines, $line_id + $offset, abs($offset) + 1, $new_lines);
                                            $line_id -= $offset;
                                        } else {
                                            array_splice($lines, $line_id, $offset + 1, $new_lines);
                                        }
                                        break;

                                    case 'before':
                                        $new_lines = explode("\n", $add);
                                        array_splice($lines, max(0, $line_id - $offset), 0, $new_lines);
                                        $line_id += count($new_lines);
                                        break;

                                    case 'after':
                                        $new_lines = explode("\n", $add);
                                        array_splice($lines, min(count($lines), ($line_id + 1) + $offset), 0, $new_lines);
                                        $line_id += count($new_lines);
                                        break;
                                }

                                // Stop if specific index matched (no need to continue)
                                if ($targetIndex !== -1) break;
                            }
                        }

                        $modified = implode("\n", $lines);
                    }
                }

                file_put_contents($fullPath, $modified);
            }
        }

        return true;
    }

    private function isActiveModule($moduleName)
    {
        // Only apply if installed and active
        if ($moduleName && (in_array($moduleName, self::$active_modules) || \Module::isEnabled($moduleName))) {
            self::$active_modules[] = $moduleName;
            return true;
        }

        return false;
    }

    public function revert()
    {
        $directory = $this->rootDir;
        $this->bakFiles = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'bak') {
                $this->bakFiles[] = $bakFile = $file->getPathname();

                $old_file = rtrim($bakFile, '.bak');
                if (file_exists($bakFile)) {
                    copy($bakFile, $old_file);
                    unlink($bakFile);
                    print_r($bakFile, $old_file);
                }
            }
        }

        return true;
    }

    public function getBackupFiles()
    {
        return $this->bakFiles;
    }
}
