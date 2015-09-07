<?php
/**
 * @file
 * File downloader provider.
 *
 * Specific implementation of resource providers should implement this class.
 */

namespace Drupal\drupal_file_downloader;

/**
 * Class DownloaderProvider.
 *
 * @package Drupal\drupal_file_downloader
 */
abstract class DownloaderProvider {
  /**
   * Remote directory.
   *
   * @var string
   */
  protected $remoteDir;

  /**
   * Local directory.
   *
   * @var string
   */
  protected $localDir;

  /**
   * Flag to store downloaded files as managed.
   *
   * @var bool
   */
  protected $managed;

  /**
   * Flag to use verbose output.
   *
   * @var bool
   */
  protected $verbose;

  /**
   * Class constructor.
   *
   * @param string $options
   *   Options array with the following keys:
   *   - remote_dir: Remote directory.
   *   - local_dir: Local directory.
   *   - provider_config: Additional provider configuration array. Each provider
   *     defines it's own list of required parameters, some of which may be
   *     compulsory. An exception will be thrown in case if some expected
   *     parameters are not provided.
   *   - managed: (optional) Flag to store downloaded files as managed. Defaults
   *     to FALSE.
   *   - verbose: (optional) Flag to use verbose output. Defaults to TRUE.
   */
  public function __construct($options) {
    $this->checkRequirements();
    $this->options = $options;
    $this->localDir = $this->normaliseFilePath($options['local_dir']);
    $this->remoteDir = $options['remote_dir'];
    $this->managed = $options['managed'];
    $this->verbose = $options['verbose'];

    // Validate provider configuration to make sure that all values that
    // provider expects were provided.
    $this->providerConfigValidate();
  }

  /**
   * Perform download and all related processing.
   *
   * @return []
   *   Array of downloaded files keyed by 'fid' for managed or real file
   *   path for non-managed downloaded files.
   */
  public function download() {
    $downloaded_files = [];

    // Get a list of files to download. This is a list of files on the remote
    // server.
    $prospective_files = $this->getList();

    // Perform further actions only if there are files to download.
    if (!empty($prospective_files)) {
      // Prepare local dir.
      $this->prepareLocalDir($this->localDir);

      // Perform files download.
      $downloaded_files = $this->performDownload($prospective_files);

      // Save as managed files, if required.
      if ($this->managed) {
        $downloaded_files = $this->saveManaged($downloaded_files);
      }
    }

    // Output result, if required.
    if ($this->verbose) {
      $this->verboseResult($prospective_files, $downloaded_files);
    }

    return $downloaded_files;
  }

  /**
   * Save downloaded managed files.
   *
   * @param [] $downloaded_files
   *   Array of downloaded files.
   */
  protected function saveManaged(array $downloaded_files) {
    foreach ($downloaded_files as $file_path => $file_name) {
      $local_file_loaded = file_get_contents($file_path);
      $file = file_save_data($local_file_loaded, $file_path, FILE_EXISTS_REPLACE);

      if ($file) {
        if ($this->verbose) {
          $this->messageSet(t('Saved file %filename as managed file with fid %fid', [
            '%filename' => $file_name,
            '%fid' => $file->fid,
          ]));
        }
      }
      else {
        // Remove the file.
        file_unmanaged_delete($file_path);
        // Unset downloaded file if it was not saved as managed.
        unset($downloaded_files[$file_path]);

        if ($this->verbose) {
          $this->messageSet(t('Unable to save file %filename as managed file', [
            '%filename' => $file_name,
          ]));
        }
      }
    }

    return $downloaded_files;
  }

  /**
   * Validate presence of provider configuration options.
   *
   * @see providerRequiredConfig()
   */
  protected function providerConfigValidate() {
    $required_config = $this->providerRequiredConfig();
    foreach ($required_config as $name) {
      if (!isset($this->options['provider_config'][$name])) {
        throw new \Exception(t('Unable to retrieve provider configuration option %name', [
          '%name' => $name,
        ]));
      }
    }
  }

  /**
   * Definition of required provider configuration options.
   *
   * @return []
   *   Array of option names.
   */
  protected function providerRequiredConfig() {
    return [];
  }

  /**
   * Get provider configuration option by name.
   *
   * @param string $name
   *   Name of the provided configuration option.
   *
   * @return mixed|null
   *   Provided option or NULL if option does not exist.
   */
  protected function getProviderConfig($name) {
    return isset($this->options['provider_config'][$name]) ? $this->options['provider_config'][$name] : NULL;
  }

  /**
   * Verbose result output.
   *
   * @param [] $remote_files
   *   Array of remote files that supposed to be downloaded.
   * @param [] $downloaded_files
   *   Array of actually downloaded files.
   */
  public function verboseResult(array $remote_files, array $downloaded_files) {
    $prospective_files = [];
    foreach ($remote_files as $file_path => $file_name) {
      $prospective_files[ltrim($this->localDir, '/') . '/' . $file_path] = $file_name;
    }

    if (count($remote_files) == count(array_intersect_key($downloaded_files, $prospective_files))) {
      $this->messageSet(format_string('Downloaded all @prospective files to local directory @localdir.', [
        '@prospective' => count($downloaded_files),
        '@localdir' => $this->localDir,
      ]));
    }
    else {
      $this->messageSet(format_string('Downloaded @downloaded from @prospective files to local directory @localdir.', [
        '@downloaded' => count($downloaded_files),
        '@prospective' => count($remote_files),
        '@localdir' => $this->localDir,
      ]));
    }
  }

  /**
   * Helper to print a message.
   *
   * Prints to stdout if using drush, or drupal_set_message() if the web UI.
   *
   * @param string $message
   *   String containing a message.
   * @param string $prefix
   *   Prefix to be used for messages when called through CLI. Defaults
   *   to '-- '.
   * @param int $indent
   *   Indent for messages. Defaults to 2.
   */
  public function messageSet($message, $prefix = '-- ', $indent = 2) {
    if (function_exists('drush_print')) {
      drush_print(((string) $prefix) . strip_tags(html_entity_decode($message)), $indent);
    }
    else {
      drupal_set_message($message);
    }
  }

  /**
   * Retrieve managed file object using specified URI.
   *
   * @param string $uri
   *   File URI.
   *
   * @return bool|object
   *   Managed file object or FALSE if file was not found.
   */
  public function getManagedFileByUri($uri) {
    $files = file_load_multiple([], ['uri' => $uri]);
    if (count($files)) {
      return reset($files);
    }

    return FALSE;
  }

  /**
   * Helper to prepare local directory to be writable by the server.
   *
   * @param string $dir
   *   Directory to prepare as URI or real path.
   *
   * @throws \Exception
   *   Exception if provided directory cannot be prepared.
   */
  protected function prepareLocalDir($dir) {
    $dir = $this->normaliseFilePath($dir);

    if (!file_prepare_directory($dir, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS)) {
      throw new \Exception(t('Unable to prepare directory @dir', ['@dir' => $dir]));
    }
  }

  /**
   * Normalise provided path to a system-recognised URI wrapper.
   *
   * @param string $path
   *   Path to normalise.
   *
   * @return string
   *   Normalised path as a 'public://' stream wrapper or original path.
   */
  protected function normaliseFilePath($path) {
    $path = strpos($path, '://') === FALSE ? 'public://' . $path : $path;

    return $path;
  }

  /**
   * Perform provider-defined requirements check.
   *
   * This method must throw exceptions for any unmet requirements.
   */
  protected function checkRequirements() {
  }

  /**
   * Retrieve a list of remote files.
   *
   * Only the files returned by this method will be downloaded.
   *
   * @return []
   *   Array of files in remote dir keyed by path in remote dir without the dir
   *   itself. I.e., /remote/dir/subdir/file.txt will have the key of
   *   'subdir/file.txt' if '/remote/dir' specified as remote dir.
   *   This is due to the fact that it is not always possible to get full
   *   remote path using listing parsing method.
   */
  abstract protected function getList();

  /**
   * Perform actual download.
   *
   * @param [] $files_to_download
   *   Array of files to download on the remote server. Array represents data
   *   returned from getList().
   *
   * @return []
   *   Array of downloaded files keyed by local file URI.
   */
  abstract protected function performDownload(array $files_to_download);

}
