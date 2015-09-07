<?php
/**
 * @file
 * Downloader provider implementation for Amazon S3.
 */

namespace Drupal\drupal_file_downloader;

use Aws\CloudFront\Exception\Exception;

/**
 * Class DownloaderProviderS3.
 *
 * @package Drupal\drupal_file_downloader
 */
class DownloaderProviderS3 extends DownloaderProvider {

  /**
   * S3 bucket name.
   *
   * @var string
   */
  public $bucket;

  /**
   * S3 client configuration set in s3fs module.
   *
   * @var []
   */
  public $clientConfig;

  /**
   * S3 client initialised by s3fs module.
   *
   * @var object
   */
  public $client;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $options) {
    parent::__construct($options);

    // Set bucket from provider config.
    $this->bucket = $this->getProviderConfig('bucket');

    // Get client config and client instance.
    $this->clientConfig = $this->initClientConfig();
    $this->client = $this->initClient();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkRequirements() {
    if (!module_exists('s3fs')) {
      throw new \Exception(t('Unmet requirements: module s3fs does not exist.'));
    }

    $library = _s3fs_load_awssdk2_library();
    if (!$library['installed'] || !$library['loaded']) {
      throw new \Exception(t('Unmet requirements: awssdk2 library is not installed.'));
    }

    // Check that sf3fs module's region variable is set.
    // If the variable is not set, the bucket will be requested from the
    // incorrect region and may result in unpredictable exceptions.
    $region = variable_get('s3fs_region', FALSE);
    if (!$region) {
      throw new \Exception('Unmet requirements: $["s3fs_region"] variable is not set to one of the AWS regions.');
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function providerRequiredConfig() {
    return [
      'bucket',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getList() {
    $files = [];

    $iterator = $this->client->getIterator('ListObjects', [
      'Bucket' => $this->bucket,
      'Prefix' => $this->remoteDir,
    ]);

    foreach ($iterator as $object) {
      // Skip directories.
      if ($object['Size'] > 0) {
        $files[substr($object['Key'], strlen(trim($this->remoteDir, '/')) + 1)] = basename($object['Key']);
      }
    }

    return $files;
  }

  /**
   * {@inheritdoc}
   */
  public function performDownload(array $files) {
    $downloaded_files = [];

    foreach ($files as $filepath => $filename) {
      $local_path = $this->localDir . '/' . $filepath;
      // Create directory hierarchy for locally saved files as getObeject()
      // below cannot create subdirectories.
      if (dirname($local_path) != '.') {
        $this->prepareLocalDir(dirname($local_path));
      }

      try {
        $this->client->getObject([
          'Bucket' => $this->bucket,
          'Key' => $this->remoteDir . '/' . $filepath,
          'SaveAs' => $local_path,
        ]);

        if ($this->verbose) {
          $this->messageSet(t('Downloaded file %filename', [
            '%filename' => $filename,
          ]));
        }

        $filepath2 = $this->localDir . '/' . $filepath;
        $downloaded_files[$filepath2] = $filename;
      }
      catch (\Exception $e) {
        if ($this->verbose) {
          $this->messageSet(t('Unable to download file %filename: %message', [
            '%filename' => $filename,
            '%message' => $e->getMessage(),
          ]));
        }
      }
    }

    return $downloaded_files;
  }

  /**
   * Initialise client configuration.
   *
   * @return []
   *   Array of configuration retrieved from s3fs module.
   */
  protected function initClientConfig() {
    return _s3fs_get_config();
  }

  /**
   * Initialise client instance.
   *
   * @return object
   *   S3 client instance retrieved from s3fs module.
   */
  protected function initClient() {
    return _s3fs_get_amazons3_client($this->clientConfig);
  }

}
