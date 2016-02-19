<?php

namespace Wpmirror;

/**
 * Class Utils
 * @package Wpmirror
 */
class Utils
{

    /**
     * Used during recurse scanning to keep track of the root
     *
     * @var string
     */
    public $rootPath = '';

    /**
     * Default 1MB size limit for md5 checksum calculations
     * Larger files will have checksum = md5(size).
     *
     * @var int
     */
    public $md5SizeLimit = 1048576;

    /**
     * An array of WordPress specific folders to skip,
     * we should make this a configuration item
     *
     * @var array
     */
    public $ignore = array(
        'wp-snapshots',              // plugin: duplicator
        'wp-content/cache',          // General cache folder
        '*~',                        // Temp files
    );

    /**
     * Entry point for scanning
     *
     * @param string $root The root folder to scan
     * @return string
     */
    public function localScan($root)
    {
        $this->rootPath = $root;
        $fileHandle = tmpfile();
        $this->recScandir($root, $fileHandle);
        $size = ftell($fileHandle);
        fseek($fileHandle, 0);
        $ret = fread($fileHandle, $size + 1);
        fclose($fileHandle);
        return $ret;
    }

    /**
     * Recursive folder scanner
     *
     * @param string   $dir
     * @param resource $f
     */
    private function recScandir($dir, $f)
    {
        $dir = rtrim($dir, '/');
        $root = scandir($dir);
        foreach ($root as $value) {
            if ($value === '.' || $value === '..') {
                continue;
            }
            if ($this->fnInArray("$dir/$value", $this->ignore)) {
                continue;
            }
            if (is_file("$dir/$value")) {
                $this->fileInfo2File($f, "$dir/$value");
                continue;
            }
            $this->fileInfo2File($f, "$dir/$value");
            $this->recScandir("$dir/$value", $f);
        }
    }

    /**
     * Writes out information about a file or folder
     *
     * @param resource $f
     * @param string   $file
     */
    private function fileInfo2File($f, $file)
    {
        $stat = stat($file);
        $sum = '';
        if (!is_dir($file)) {
            $sum = md5($stat['size']);
        }

        $relfile = substr($file, strlen($this->rootPath));
        $row = array(
            $relfile,
            is_dir($file) ? 0 : $stat['mtime'],
            is_dir($file) ? 0 : $stat['size'],
            $sum,
            (int)is_dir($file),
            (int)is_file($file),
            (int)is_link($file),
        );
        fwrite($f, join("\t", $row) . "\n");
    }

    /**
     * Match filename against an array patterns
     *
     * @param string $needle
     * @param array  $haystack
     * @return bool
     */
    private function fnInArray($needle, $haystack)
    {
        # this function allows wildcards in the array to be searched
        $needle = substr($needle, strlen($this->rootPath));
        foreach ($haystack as $value) {
            if (true === fnmatch($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Just read a file into a string, return an empty string if the file
     * does not exist
     *
     * @param $file
     * @return string
     */
    public function readSavedState($file)
    {
        if (file_exists($file)) {
            return file_get_contents($file);
        } else {
            return '';
        }
    }

    /**
     * Parse a tab separated string and return just the
     * columns with unique id as key and a value column
     * as value
     *
     * @param string $string
     * @param int $idCol
     * @param int $value
     * @return array
     */
    public function tabSepStringToArray($string, $idCol, $value)
    {
        $retArray = array();
        $rows = explode("\n", $string);
        foreach ($rows as $row) {
            if (strlen(trim($row)) == 0) {
                continue;
            }
            $cols = explode("\t", $row);
            $retArray[$cols[$idCol]] = $cols[$value];
        }

        return $retArray;
    }
}
