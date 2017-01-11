<?php

namespace Drupal\drupal_file_downloader;

use FtpClient\FtpClient;
use FtpClient\FtpException;

/**
 * Class DownloaderProviderFtp.
 *
 * @package Drupal\drupal_file_downloader
 */
class DownloaderProviderFtp extends DownloaderProvider {

  /**
   * FTP server host.
   *
   * @var string
   */
  public $host;

  /**
   * FTP connection port.
   *
   * @var int
   */
  public $port;

  /**
   * FTP connection username.
   *
   * @var string
   */
  public $username;

  /**
   * FTP connection password.
   *
   * @var string
   */
  public $password;


  /**
   * Path to a root of the common download location.
   *
   * This is not a root of the FTP account's directory, but rather a common
   * location where all subdirectories and files are stored.
   *
   * @var string
   */
  public $rootPath;

  /**
   * Timeout in seconds for FTP operations.
   *
   * @var int
   */
  public $timeout;

  /**
   * FTP client.
   *
   * @var \FtpClient\FtpClient
   */
  public $client;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $options) {
    $this->host = $this->getProviderConfig($options, 'host');
    $this->port = $this->getProviderConfig($options, 'port', 21);
    $this->username = $this->getProviderConfig($options, 'username');
    $this->password = $this->getProviderConfig($options, 'password');
    $this->rootPath = $this->getProviderConfig($options, 'root_path', '.');
    $this->timeout = $this->getProviderConfig($options, 'timeout', 90);

    parent::__construct($options);

    $this->initConnection();
  }

  /**
   * Initialise FTP connection.
   *
   * @throws \FtpClient\FtpException
   *   When connection cannot be established.
   */
  protected function initConnection() {
    $this->client = new FtpClient();

    try {
      // Initialise connection.
      $this->client->connect($this->host, FALSE, $this->port, $this->timeout);
      // Authenticate.
      $this->client->login($this->username, $this->password);
      // Set passive mode.
      $this->client->pasv(TRUE);
      // Change directory to the root path.
      $this->client->chdir($this->rootPath);
    }
    catch (FtpException $e) {
      throw new FtpException(t('Unable to initialise FTP connection: %message', [
        '%message' => $e->getMessage(),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkRequirements() {
    $library = libraries_load('php-ftp-client');

    if (!$library['installed'] || !$library['loaded']) {
      throw new \Exception(t('Unmet requirements: php-ftp-client library is not installed.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function providerConfigOptions() {
    return [
      'host' => FALSE,
      'port' => FALSE,
      'username' => FALSE,
      'password' => FALSE,
      'root_path' => FALSE,
      'timeout' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getList() {
    $files = [];

    try {
      // Recursively scan all files and directories within a remote dir.
      $files_data = $this->client->scanDir($this->remoteDir, TRUE);
    }
    catch (FtpException $e) {
      throw new FtpException(t('Unable to get a list of files from FTP resource: %message', [
        '%message' => $e->getMessage(),
      ]));
    }

    foreach ($files_data as $key => $item) {
      if (strpos($key, 'file#') !== 0) {
        continue;
      }
      $file_path = substr($key, strlen('file#') + strlen($this->remoteDir) + 1);

      $files[$file_path] = $item['name'];
    }

    return $files;
  }

  /**
   * {@inheritdoc}
   */
  public function performDownload(array $files_to_download) {
    $downloaded_files = [];

    foreach (array_keys($files_to_download) as $remote_file_path) {
      $remote_file_path_full = $this->remoteDir . '/' . $remote_file_path;
      $local_path_full = $this->localDir . '/' . $remote_file_path;

      // Create directory hierarchy for locally saved files.
      if (dirname($local_path_full) != '.') {
        $this->prepareLocalDir(dirname($local_path_full));
      }

      // Perform actual download.
      $download_result = $this->client->get($local_path_full, $remote_file_path_full, FTP_BINARY);
      if (!$download_result) {
        if ($this->verbose) {
          $this->messageSet(t('Unable to download file %filename', [
            '%filename' => $remote_file_path_full,
          ]));
        }
        continue;
      }

      if ($this->verbose) {
        $this->messageSet(t('Downloaded file %filename', [
          '%filename' => $remote_file_path_full,
        ]));
      }

      $downloaded_files[] = $local_path_full;
    }

    return $downloaded_files;
  }

}
