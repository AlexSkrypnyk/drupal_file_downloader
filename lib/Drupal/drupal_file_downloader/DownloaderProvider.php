<?php

namespace Drupal\drupal_file_downloader;

/**
 * Class DownloaderProvider.
 *
 * Specific implementation of resource providers should implement this class.
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
   * Call this constructor after parsing options in provider class constructors.
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
    $this->localDir = $this->normaliseFilePath($options['local_dir']);
    $this->remoteDir = $options['remote_dir'];
    $this->managed = $options['managed'];
    $this->verbose = $options['verbose'];

    // Validate provider configuration to make sure that all values that
    // provider expects were provided and are not empty.
    $this->providerConfigValidate($options['provider_config']);

    // Check requirements.
    $this->checkRequirements();
  }

  /**
   * Perform download and all related processing.
   *
   * @return array
   *   Array of downloaded files:
   *   - if 'managed' was set to TRUE, keys are 'fid' and values are URI.
   *   - if 'managed' was set to FALSE, keys are numeric and values are paths.
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
      $this->messageSet(format_string('Downloaded @downloaded from @total@managed files to local directory @localdir.', [
        '@downloaded' => count($downloaded_files),
        '@total' => count($prospective_files),
        '@localdir' => $this->localDir,
        '@managed' => $this->managed ? ' managed' : '',
      ]));
    }

    return $downloaded_files;
  }

  /**
   * Get current provider name.
   *
   * @return string
   *   Provider name.
   */
  public function getName() {
    return strtolower(substr(get_class($this), strlen(get_parent_class($this))));
  }

  /**
   * Save downloaded managed files.
   *
   * @param array $downloaded_files
   *   Array of downloaded full file paths.
   */
  protected function saveManaged(array $downloaded_files) {
    $saved_files = [];
    foreach ($downloaded_files as $file_path) {
      $local_file_loaded = file_get_contents($file_path);
      $file = file_save_data($local_file_loaded, $file_path, FILE_EXISTS_REPLACE);

      if ($file) {
        $saved_files[$file->fid] = $file->uri;
        if ($this->verbose) {
          $this->messageSet(t('Saved managed file %uri [fid:%fid]', [
            '%uri' => $file->uri,
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
            '%filename' => $file_path,
          ]));
        }
      }
    }

    return $saved_files;
  }

  /**
   * Validate that provider configuration values exist.
   *
   * @param array $config
   *   Array of provider config options.
   *
   * @throws \Exception
   *   Throws exception when:
   *   - required provider config items are not provided through options.
   *   - any of expected configuration items is empty.
   *
   * @see providerConfigOptions()
   */
  protected function providerConfigValidate(array $config) {
    $config_options = $this->providerConfigOptions();
    foreach ($config_options as $name => $required) {
      // Validate that required provider config options were specified.
      if ($required && empty($config[$name])) {
        throw new \Exception(t('Unable to retrieve required provider configuration option %name', [
          '%name' => $name,
        ]));
      }

      // Validate that all options are not empty.
      $name_camel = str_replace('_', '', lcfirst(ucwords($name, '_')));
      if (empty($this->{$name_camel})) {
        throw new \Exception(t('Provider configuration option %name is empty', [
          '%name' => $name,
        ]));
      }
    }
  }

  /**
   * Definition of provider configuration options.
   *
   * @return array
   *   Array of option names, keyed by config options with TRUE values
   *   for required and FALSE for optional options.
   */
  protected function providerConfigOptions() {
    return [];
  }

  /**
   * Get provider configuration option by name.
   *
   * This allows to dynamically set variables using Drupal variables and/or
   * provide them through $options['provider_config'].
   * Providing values through $options['provider_config'] takes precedence over
   * Drupal variables.
   *
   * @param string $name
   *   Name of the provided configuration option.
   * @param mixed $default
   *   Optional default value if config is not available. Defaults to NULL.
   *
   * @return mixed|null
   *   Provided option or NULL if option does not exist.
   */
  protected function getProviderConfig($options, $name, $default = NULL) {
    if (isset($options[$name])) {
      return $options[$name];
    }

    return variable_get('drupal_file_downloader_' . $this->getName() . '_' . $name, $default);
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
   * @return array
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
   * @param array $files_to_download
   *   Array of files to download on the remote server. Array represents data
   *   returned from getList().
   *
   * @return array
   *   Array of downloaded local file URIs.
   */
  abstract protected function performDownload(array $files_to_download);

}
