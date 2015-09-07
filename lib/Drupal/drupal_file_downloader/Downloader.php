<?php
/**
 * @file
 * Drupal file downloader.
 */

namespace Drupal\drupal_file_downloader;

/**
 * Class Downloader.
 *
 * @package Drupal\drupal_file_downloader
 */
class Downloader {

  /**
   * Downloader provider instance.
   *
   * @var \Drupal\drupal_file_downloader\DownloaderProvider
   */
  protected static $downloadProvider;

  /**
   * Perform file download.
   *
   * Example usage:
   *
   * @code
   * // This will download all files from all subdirectories of remote Amazon S3
   * // bucket directory 'remote_dir' into local directory 'public://remote_dir'
   * // as non-managed files and will print file download result.
   * // The return will have all downloaded files as array keyed by 'fid' for
   * // managed files or by real file paths for non-managed files.
   * $downloaded_files = Drupal\drupal_file_downloader\Downloader::download('S3', 'remote_dir', ['provider_config' => ['bucket' => 'mybucket.example.com']]);
   * @endcode
   *
   * @param string $provider
   *   Downloader provider from one of the existing implementations. I.e., 'S3'.
   * @param string $remote_dir
   *   Remote directory.
   * @param string $options
   *   Options array with the following keys:
   *   - remote_dir: (optional) Remote directory. Defaults to $remote_dir.
   *   - local_dir: (optional) Local directory. Defaults to the same name as a
   *     remote directory, but within public files.
   *   - provider_config: Additional provider configuration array. Each provider
   *     defines it's own list of required parameters, some of which may be
   *     compulsory. An exception will be thrown in case if some expected
   *     parameters are not provided.
   *   - managed: (optional) Flag to store downloaded files as managed. Defaults
   *     to FALSE.
   *   - verbose: (optional) Flag to use verbose output. Defaults to TRUE.
   *
   * @return []
   *   Array of downloaded files keyed by 'fid' for managed or real file
   *   path for non-managed downloaded files.
   */
  static public function download($provider, $remote_dir, $options = []) {
    // Get validated downloader class.
    $class = self::getProviderClass($provider);

    // Merge all options with later values overwriting the previous ones.
    $options = self::mergeOptions(['remote_dir' => $remote_dir], $options);

    // Default local dir to the remote dir, if not provided.
    $options['local_dir'] = empty($options['local_dir']) ? 'public://' . $options['remote_dir'] : $options['local_dir'];

    // Create downloader class and perform a download.
    self::$downloadProvider = new $class($options);

    return self::$downloadProvider->download();
  }

  /**
   * Cleanup files created during download().
   *
   * It is expected that $files is returned from download(). Also, be aware that
   * each parent directory in the hierarchy may be removed if there are no more
   * files left in that directory.
   *
   * @param [] $files
   *   Array of files as returned from download() method.
   */
  static public function cleanup(array $files) {
    foreach ($files as $file_path => $file_name) {
      // Handle managed files.
      if (count(file_load_multiple([], ['uri' => $file_path])) > 0) {
        $file_obj = new \stdClass();
        $file_obj->uri = $file_path;
        file_delete($file_obj);
      }
      else {
        file_unmanaged_delete($file_path);
      }

      // Remove all directories in the tree if the file was the last one in this
      // directory.
      $file_dir = dirname($file_path);
      while (count(file_scan_directory($file_dir, '/.*/')) === 0) {
        if (!is_dir($file_dir)) {
          break;
        }
        drupal_rmdir($file_dir);
        $file_dir = dirname($file_dir);
      }
    }
  }

  /**
   * Merge all options with later values overwriting the previous ones.
   *
   * @param ...
   *   Arrays to be merged.
   *
   * @return []
   *   Array of merged options.
   */
  protected static function mergeOptions() {
    $merged_options = [];

    $defaults = [
      'local_dir' => '',
      'managed' => FALSE,
      'verbose' => TRUE,
      'provider_config' => [],
    ];

    $args = array_reverse(func_get_args());
    foreach ($args as $arg) {
      $merged_options += $arg;
    }
    $merged_options += $defaults;

    return $merged_options;
  }

  /**
   * Assemble and validate provider class name.
   *
   * @param string $type
   *   Downloader provider type. Must be one of the available implementations.
   *
   * @return string
   *   Fully assembled and validated downloader provider class name.
   */
  protected static function getProviderClass($type) {
    $provider_base_class = $provider_type_class = __NAMESPACE__ . '\DownloaderProvider';
    $provider_type_class = $provider_base_class . ucfirst($type);

    if (!class_exists($provider_type_class)) {
      throw new \Exception('Incorrect download provider type was specified.');
    }

    // Check that the class is an implementation of abstract downloader
    // provider.
    if (!in_array($provider_base_class, class_parents($provider_type_class))) {
      throw new \Exception('Incorrect implementation of downloader provider detected.');
    }

    return $provider_type_class;
  }

}
