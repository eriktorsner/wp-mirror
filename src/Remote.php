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
     * @param $ftpSettings
     */
    public function __construct($remoteSettings)
    {
        $this->remoteSettings = $remoteSettings;

        $this->maxFiles = 100;
        if (isset($remoteSettings->maxFiles)) {
            $this->maxFiles = $remoteSettings->maxFiles;
        }
    }

    /**
     * @return bool|string
     */
    public function remoteScan()
    {
        $baseUrl = rtrim($this->remoteSettings->url, '/') . '/';
        $baseUrl = $this->properUrl($baseUrl);

        // 1. Get a seed
        $url = $baseUrl . '?wpmirroragent_token=nosession';
        $url .= '&wpmirroragent_action=seed';

        $strResponse = file_get_contents($url);
        $response = json_decode($strResponse);
        if (!$response) {
            // log and exit
        }
        $this->seed = $response->seed;

        // 2. Get the file index
        $url = $baseUrl . '?wpmirroragent_token=' . $this->calcRemoteToken('fileindex');
        $url .= '&wpmirroragent_action=fileindex';
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
echo "1\n";
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

    private function getFilesSubset($files)
    {
        // ask to pack the files
        $baseUrl = rtrim($this->remoteSettings->url, '/') . '/';
        $baseUrl = $this->properUrl($baseUrl);
        $url = $baseUrl . '?wpmirroragent_token=' . $this->calcRemoteToken('fileindex');
        $url .= '&wpmirroragent_action=pack';
        $params = array('http' => array(
                'method' => 'POST',
                'header' => 'Content-type: application/json',
                'content' => json_encode($files),
            )
        );
        $ctx = stream_context_create($params);

        echo "url: $url \n";
        // download the zip
        $target = tempnam('', 'mirror_');
        file_put_contents($target, fopen($url, 'rb', false, $ctx));
        echo $target . "\n";

        // unpack the file
        $this->unpackZip($target);
        //unlink($target);

    }

    private function unpackZip($fileName)
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

    public function cleanUp()
    {
    }

    /**
     * Ensure the URL has a valid protocol
     *
     * @param string $url
     * @return string
     */
    private function properUrl($url)
    {
        if (substr($url, 0, 4) != 'http') {
            $url = 'http://'. $url;
        }

        return $url;
    }

    private function calcRemoteToken($command)
    {
        return sha1($this->seed . $this->remoteSettings->secret . $command);
    }
}
