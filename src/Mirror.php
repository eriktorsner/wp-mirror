<?php

namespace Wpmirror;

/**
 * Class Mirror
 * @package Wpmirror
 */
class Mirror
{
    /**
     * @var string
     */
    private $currentLocalState = '';

    /**
     * @var string
     */
    private $currentRemoteState = '';

    /**
     * @var Utils
     */
    private $utils;

    /**
     * @var Remote
     */
    private $remote;

    /**
     * @var Settings
     */
    private $remoteSettings;

    /**
     * @var string
     */
    private $stateFolder;


    public function mirror()
    {
        $this->remoteSettings = new Settings('remote');
        $this->utils = new Utils();
        $this->remote = new Remote($this->remoteSettings);

        $this->mirrorFiles();
        $this->rewriteWpConfig();
        $this->mirrorDb();
        $this->rewriteDb();

        // Grab another local snapshot
        $this->currentLocalState = $this->utils->localScan($this->remoteSettings->localpath);
        $this->saveState();

        // if all went well (how do we know?), we're still here... so save state
        $this->remote->cleanUp();
    }

    private function mirrorFiles()
    {
        $this->stateFolder = BASEPATH . '/wpmirror';
        if (isset($this->remoteSettings->stateFolder)) {
            $this->stateFolder = $this->remoteSettings->stateFolder;
        }

        $localChanges = $this->getModifiedLocalFiles();
        $remoteChanges = $this->getModifiedRemoteFiles();
        $missingLocal = $this->getMissingLocalFiles();

        // Merge the changes together
        foreach ($localChanges as $file => $change) {
            if (!isset($remoteChanges[$file])) {
                $remoteChanges[$file] = $change;
            }
        }
        foreach ($missingLocal as $file => $change) {
            if (!isset($remoteChanges[$file])) {
                $remoteChanges[$file] = $change;
            }
        }

        // Get the changes from the remote server:
        $this->remote->getFiles($remoteChanges);

    }

    private function mirrorDb()
    {
        // get the db tables...
        $this->remote->getDatabase();
    }

    private function rewriteWpConfig()
    {
        $wpConfig = $this->remoteSettings->localpath . '/wp-config.php';
        if (file_exists($wpConfig)) {
            unlink($wpConfig);
        }

        $wpcmd = $this->getWpCommand();

        $cmd = $wpcmd.sprintf(
            'core config --dbhost=%s --dbname=%s --dbuser=%s --dbpass=%s --quiet',
            $this->remoteSettings->localdbhost,
            $this->remoteSettings->localdbname,
            $this->remoteSettings->localdbuser,
            $this->remoteSettings->localdbpass
        );
        exec($cmd);
    }

    private function rewriteDb()
    {
        $wpcmd = $this->getWpCommand();

        $remote = $this->remote->properUrl($this->remoteSettings->url);
        $local  = $this->remote->properUrl($this->remoteSettings->localurl);
        $cmd = $wpcmd.sprintf(
            'search-replace %s %s --skip-columns=guid',
            $remote,
            $local
        );
    }

    /**
     * Save local and remote state
     */
    private function saveState()
    {
        if (!file_exists($this->stateFolder)) {
            mkdir($this->stateFolder, 0700, true);
        }
        file_put_contents($this->stateFolder . '/localstate', $this->currentLocalState);
        file_put_contents($this->stateFolder . '/remotestate', $this->currentRemoteState);
    }

    /**
     * Get the current state of all files and compare it
     * to a saved state. Return a changeset.
     *
     * @return array
     */
    private function getModifiedLocalFiles()
    {
        // 1. Scan local folders, use md5 sum locally
        $this->currentLocalState = $this->utils->localScan($this->remoteSettings->localpath);
        $currentArray = $this->utils->tabSepStringToArray($this->currentLocalState, 0, 3);
        $saved = $this->utils->readSavedState($this->stateFolder . '/localstate');
        $savedArray = $this->utils->tabSepStringToArray($saved, 0, 3);

        $changeSet= array();

        foreach ($savedArray as $file => $sum) {
            if (isset($currentArray[$file])) {
                if ($currentArray[$file] != $sum) {
                    $changeSet[$file] = array('state' => 'LOCALMOD', 'file' => $file);
                }
            } else {
                $changeSet[$file] = array('state' => 'LOCALDEL', 'file' => $file);
            }
        }

        foreach ($currentArray as $file => $sum) {
            if (!isset($savedArray[$file])) {
                $changeSet[$file] = array('state' => 'LOCALNEW', 'file' => $file);
            }
        }

        return $changeSet;
    }

    /**
     * @return array
     */
    private function getModifiedRemoteFiles()
    {
        $this->currentRemoteState = $this->remote->remoteScan();
        $currentArray = $this->utils->tabSepStringToArray($this->currentRemoteState, 0, 3);
        $saved = $this->utils->readSavedState($this->stateFolder . '/remotestate');
        $savedArray = $this->utils->tabSepStringToArray($saved, 0, 3);

        $changeSet= array();

        foreach ($savedArray as $file => $sum) {
            if (isset($currentArray[$file])) {
                if ($currentArray[$file] != $sum) {
                    $changeSet[$file] = array('state' => 'REMOTEMOD', 'file' => $file);
                }
            } else {
                $changeSet[$file] = array('state' => 'REMOTEDEL', 'file' => $file);
            }
        }

        return $changeSet;
    }

    /**
     * Identify files that exist remotely but not locally
     *
     * @return array
     */
    private function getMissingLocalFiles()
    {
        // get array with file path/name as key and size as value
        $currentLocal  = $this->utils->tabSepStringToArray($this->currentLocalState, 0, 2);
        $currentRemote =   $this->utils->tabSepStringToArray($this->currentRemoteState, 0, 2);

        $changeSet= array();
        foreach ($currentRemote as $file => $size) {
            if (!isset($currentLocal[$file])) {
                $changeSet[$file] = array('state' => 'missing', 'file' => $file);
            }
        }

        return $changeSet;
    }

    /**
     * Returns the wp-cli command with correct path
     *
     * @return string
     */
    public function getWpCommand()
    {
        $wpcmd = 'wp --path='.$this->remoteSettings->localpath.' --allow-root ';

        return $wpcmd;
    }
}
