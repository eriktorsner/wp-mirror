<?php

namespace Wpmirror;

/**
 * Class Remote
 * @package Wpmirror
 */
class Remote
{
    private $remoteSettings;

    /**
     * Remote constructor.
     *
     * @param Settings $remoteSettings
     */
    public function __construct($remoteSettings)
    {
        $this->remoteSettings = $remoteSettings;

        $this->baseUrl = rtrim($this->remoteSettings->url, '/') . '/';
        $this->baseUrl = $this->properUrl($this->baseUrl);


        $this->maxFiles = 100;
        if (isset($remoteSettings->maxFiles)) {
            $this->maxFiles = $remoteSettings->maxFiles;
        }

        $this->initiateRemoteSession();
    }

    /**
     * @return bool|string
     */
    public function remoteScan()
    {
        $url = $this->getUrl('fileindex');
        $strResponse = file_get_contents($url);

        return $strResponse;
    }

    /**
     * Iterates through the $changeSet array and request that the remote server
     * creates and makes available a zip archive with those files.
     *
     * @param array $changeSet
     * @return object
     */
    public function getFiles($changeSet)
    {

        if (count($changeSet) == 0) {
            return json_decode('{"messages":[]}');
        }
        $files = array();
        foreach ($changeSet as $change) {
            $files[] = $change['file'];
            if (count($files) == $this->maxFiles) {
                $this->getFilesSubset($files);
                $files = array();
            }
        }
        if (count($files) > 0) {
            $this->getFilesSubset($files);
        }
    }

    /**
     *
     */
    public function getDatabase()
    {
        // Get the table index
        $url = $url = $this->getUrl('tableindex');
        $strResponse = file_get_contents($url);
        $arrTables = explode("\n", $strResponse);

        foreach ($arrTables as $table) {
            if (strlen($table) == 0) {
                continue;
            }
            $url = $this->getUrl('packtable');
            $url .= '&table=' . $table;
            $target = tempnam('', 'mirror_');
            file_put_contents($target, fopen($url, 'rb'));

            $this->unpackTableZip($table, $target);
        }
    }

    private function getFilesSubset($files)
    {
        // ask to pack the files

        $url = $this->getUrl('pack');
        $params = array('http' => array(
                'method' => 'POST',
                'header' => 'Content-type: application/json',
                'content' => json_encode($files),
            )
        );
        $ctx = stream_context_create($params);

        // download the zip
        $target = tempnam('', 'mirror_');
        file_put_contents($target, fopen($url, 'rb', false, $ctx));

        // unpack the file
        $this->unpackFilesZip($target);
        unlink($target);

    }

    private function unpackFilesZip($fileName)
    {
        $zip = new \ZipArchive();
        $zip->open($fileName);
        $base = $this->remoteSettings->localpath;

        for ($i = 0; $i<$zip->numFiles; $i++) {
            $file = $zip->getNameIndex($i);
            $success = $zip->extractTo($base, $file);
            if (!$success) {
                //$ret->messages[] = "file $file could not be extracted";
            }
        }
        $zip->close();
    }

    private function unpackTableZip($table, $fileName)
    {
        $zip = new \ZipArchive();
        $zip->open($fileName);

        $mysqli = new \mysqli(
            $this->remoteSettings->localdbhost,
            $this->remoteSettings->localdbuser,
            $this->remoteSettings->localdbpass,
            $this->remoteSettings->localdbname
        );

        $sql = 'DROP TABLE IF EXISTS ' . $table;
        $mysqli->query($sql);

        $sql = $zip->getFromIndex(0);
        $mysqli->multi_query($sql);
    }

    public function cleanUp()
    {
    }

    /**
     * Ensure the URL has a valid protocol
     *
     * @param string $url
     * @return string
     */
    public function properUrl($url)
    {
        if (substr($url, 0, 4) != 'http') {
            $url = 'http://'. $url;
        }

        return $url;
    }

    private function initiateRemoteSession()
    {
        $url = $this->baseUrl . '?wpmirroragent_token=nosession';
        $url .= '&wpmirroragent_action=seed';
        $strResponse = file_get_contents($url);
        $response = json_decode($strResponse);
        if (!$response) {
            // log and exit
        }
        $this->seed = $response->seed;
    }

    private function getUrl($command)
    {
        $url = $this->baseUrl;
        $url .= '?wpmirroragent_token=' . sha1($this->seed . $this->remoteSettings->secret . $command);
        $url .= '&wpmirroragent_action=' . $command;
        return $url;
    }
}
