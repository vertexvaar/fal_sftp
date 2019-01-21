<?php
namespace VerteXVaaR\FalSftp\Adapter;

/* Copyright (C) 2015 Oliver Eglseder <php@vxvr.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

use TYPO3\CMS\Core\Type\File\FileInfo;
use VerteXVaaR\FalSftp\Driver\SftpDriver;

/**
 * Class PhpSshAdapter
 */
class PhpSshAdapter extends AbstractAdapter
{
    /**
     * @var string[]|int[]
     */
    protected $configuration = [];

    /**
     * @var resource
     */
    protected $ssh = null;

    /**
     * @var resource
     */
    protected $sftp = null;

    /**
     * @var string
     */
    protected $sftpWrapper = '';

    /**
     * @var int
     */
    protected $sftpWrapperLength = 0;

    /**
     * @var int
     */
    protected $iteratorFlags = 0;

    /**
     * PhpSshAdapter constructor.
     *
     * @param array $configuration
     */
    public function __construct(array $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     *
     */
    public function connect()
    {
        $this->ssh = ssh2_connect(
            $this->configuration['hostname'],
            $this->configuration['port']
        );

        $username = $this->configuration['username'];

        switch ($this->configuration[SftpDriver::CONFIG_AUTHENTICATION_METHOD]) {
            case static::AUTHENTICATION_PASSWORD:
                ssh2_auth_password($this->ssh, $username, $this->configuration['password']);
                break;
            case static::AUTHENTICATION_PUBKEY:
                $publicKey = $this->configuration['publicKey'];
                $privateKey = $this->configuration['privateKey'];
                if (!file_exists($publicKey) || !file_exists($privateKey)) {
                    return;
                }
                $password = $this->configuration['privateKeyPassword'];
                if (empty($password)) {
                    $password = null;
                }
                ssh2_auth_pubkey_file($this->ssh, $username, $publicKey, $privateKey, $password);
                break;
            default:
        }
        $this->sftp = ssh2_sftp($this->ssh);
        $this->sftpWrapper = 'ssh2.sftp://' . (int)$this->sftp;
        $this->sftpWrapperLength = strlen($this->sftpWrapper);
        $this->iteratorFlags =
            \FilesystemIterator::UNIX_PATHS
            | \FilesystemIterator::SKIP_DOTS
            | \FilesystemIterator::CURRENT_AS_FILEINFO
            | \FilesystemIterator::FOLLOW_SYMLINKS;

        return true;
    }

    /**
     * @param string $hashingMethod "sha1" or "md5"
     * @return mixed
     */
    public function getForeignKeyFingerprint($hashingMethod)
    {
        switch ($hashingMethod) {
            case self::HASHING_SHA1:
                $hashingMethod = SSH2_FINGERPRINT_SHA1;
                break;
            case self::HASHING_MD5:
            default:
                $hashingMethod = SSH2_FINGERPRINT_MD5;
        }
        return ssh2_fingerprint($this->ssh, $hashingMethod);
    }

    /**
     * @param $identifier
     * @param bool $files
     * @param bool $folders
     * @param bool $recursive
     * @return array
     */
    public function scanDirectory($identifier, $files = true, $folders = true, $recursive = false)
    {
        $directoryEntries = [];
        $iterator = new \RecursiveDirectoryIterator($this->sftpWrapper . $identifier, $this->iteratorFlags);
        while ($iterator->valid()) {
            /** @var $entry \SplFileInfo */
            $entry = $iterator->current();
            $identifier = substr($entry->getPathname(), $this->sftpWrapperLength);
            if ($files && $entry->isFile()) {
                $directoryEntries[$identifier] = $this->getShortInfo($identifier, 'file');
            } elseif ($folders && $entry->isDir()) {
                $directoryEntries[$identifier] = $this->getShortInfo($identifier, 'dir');
            }
            $iterator->next();
        }
        if ($recursive) {
            foreach ($directoryEntries as $directoryEntry) {
                if ($directoryEntry['type'] === 'dir') {
                    $scanResults = $this->scanDirectory($directoryEntry['identifier'], $files, $folders, $recursive);
                    foreach ($scanResults as $identifier => $info) {
                        $directoryEntries[$identifier] = $info;
                    }
                }
            }
        }
        return $directoryEntries;
    }

    /**
     * @param string $identifier
     * @param string $type
     * @return bool
     */
    public function exists($identifier, $type = self::TYPE_FILE)
    {
        $identifier = $this->sftpWrapper . $identifier;
        if ($type === self::TYPE_FILE) {
            return is_file($identifier);
        } elseif ($type === self::TYPE_FOLDER) {
            return is_dir($identifier);
        }
        return false;
    }

    /**
     * @param string $identifier
     * @return mixed
     */
    public function getPermissions($identifier)
    {
        $path = $this->sftpWrapper . $identifier;
        return [
            'r' => (bool)is_readable($path),
            'w' => (bool)is_writable($path),
        ];
    }

    /**
     * @param string $identifier
     * @param bool $recursive
     * @return string
     */
    public function createFolder($identifier, $recursive = true)
    {
        ssh2_sftp_mkdir($this->sftp, $identifier, $this->configuration[SftpDriver::CONFIG_FOLDER_MODE], $recursive);
        return $identifier;
    }

    /**
     * @param string $identifier
     * @return array
     */
    public function getDetails($identifier)
    {
        $fileInfo = new FileInfo($this->sftpWrapper . $identifier);
        $details = [];
        $details['size'] = $fileInfo->getSize();
        $details['atime'] = $fileInfo->getATime();
        $details['mtime'] = $fileInfo->getMTime();
        $details['ctime'] = $fileInfo->getCTime();
        $details['mimetype'] = (string)$fileInfo->getMimeType();
        return $details;
    }

    /**
     * @param string $identifier
     * @param string $hashAlgorithm
     * @return string
     */
    public function hash($identifier, $hashAlgorithm)
    {
        switch ($hashAlgorithm) {
            case 'sha1':
                return sha1_file($this->sftpWrapper . $identifier);
            case 'md5':
                return md5_file($this->sftpWrapper . $identifier);
            default:
        }
        return '';
    }

    /**
     * @param string $identifier
     * @param string $target
     * @return string
     */
    public function downloadFile($identifier, $target)
    {
        if (ssh2_scp_recv($this->ssh, $identifier, $target)) {
            return $target;
        }
        throw new \RuntimeException(
            'Copying file "' . $identifier . '" to temporary path "' . $target . '" failed.',
            1447607200
        );
    }

    /**
     * @param string $source
     * @param string $identifier
     * @return string
     */
    public function uploadFile($source, $identifier)
    {
        return ssh2_scp_send($this->ssh, $source, $identifier, $this->configuration[SftpDriver::CONFIG_FILE_MODE]);
    }

    /**
     * @param string $identifier
     * @return void
     */
    public function dumpFile($identifier)
    {
        readfile($this->sftpWrapper . $identifier);
    }

    /**
     * @param string $identifier
     * @param bool $recursive
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function delete($identifier, $recursive)
    {
        if (is_dir($this->sftpWrapper . $identifier)) {
            return ssh2_sftp_rmdir($this->sftp, $identifier);
        } elseif (is_file($this->sftpWrapper . $identifier)) {
            return ssh2_sftp_unlink($this->sftp, $identifier);
        } else {
            return false;
        }
    }

    /**
     * @param string $identifier
     * @return string
     */
    public function readFile($identifier)
    {
        return file_get_contents($this->sftpWrapper . $identifier);
    }

    /**
     * @param string $oldIdentifier
     * @param string $newIdentifier
     * @return bool
     */
    public function rename($oldIdentifier, $newIdentifier)
    {
        $this->delete($newIdentifier, false);
        if (ssh2_sftp_rename($this->sftp, $oldIdentifier, $newIdentifier)) {
            return true;
        } else {
            $this->delete($oldIdentifier, false);
            return false;
        }
    }

    /**
     * @param string $sourceIdentifier
     * @param string $targetIdentifier
     * @return bool
     */
    public function copy($sourceIdentifier, $targetIdentifier)
    {
        $oldIdentifier = $this->sftpWrapper . $sourceIdentifier;
        $newIdentifier = $this->sftpWrapper . $targetIdentifier;
        if (is_dir($oldIdentifier)) {
            $items = $this->scanDirectory($sourceIdentifier, true, true, true);
            foreach ($items as $item) {
                $source = $item['identifier'];
                $target = str_replace($sourceIdentifier, $targetIdentifier, $source);
                if (is_dir($this->sftpWrapper . $source)) {
                    $this->createFolder($target);
                } else {
                    copy($this->sftpWrapper . $source, $this->sftpWrapper . $target);
                }
            }
        } else {
            return copy($oldIdentifier, $newIdentifier);
        }
    }

    /**
     * "Proper" disconnect
     * ... nope, there's no such thing as "ssh2_disconnect()"
     */
    public function __destruct()
    {
        $this->ssh = null;
    }
}
