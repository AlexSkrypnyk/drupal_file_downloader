# drupal\_file\_downloader
### Drupal module to download files from remote providers

[![Circle CI](https://circleci.com/gh/alexdesignworks/drupal_file_downloader.svg?style=shield)](https://circleci.com/gh/alexdesignworks/drupal_file_downloader)

## Why?
When using hook_update_N() to deliver updates, it is sometimes necessary to fetch files from remote location and store them. This helper module allows to have different download providers as a source of such files.

## Example
```php
// This will download all files from all subdirectories of remote Amazon S3
// bucket directory 'remote_dir' into local directory 'public://remote_dir'
// as non-managed files and will print file download result.
// The return will have all downloaded files as array keyed by 'fid' for
// managed files or by real file paths for non-managed files.
$downloaded_files = Drupal\drupal_file_downloader\Downloader::download('S3', 'remote_dir', ['provider_config' => ['bucket' => 'mybucket.example.com']]);

// Perform some operations with files.

// Cleanup downloaded files.
Drupal\drupal_file_downloader\Downloader::cleanup(downloaded_files);
```


## Supported providers
* [x] Amazon S3 - COMPLETED
* [ ] Directory listing - PLANNED

## Installation
Depending on your provider, you may need to install additional modules.

### S3 Provider
1. Install [xautoload](https://www.drupal.org/project/xautoload) module.
2. Install [s3fs](https://www.drupal.org/project/s3fs) drupal module.
3. Install AWS SDK PHP library - follow instructions on [s3fs](https://www.drupal.org/project/s3fs) module's page.
4. Set AWS access and secret key variables (add to your `settings.php`):
    * `$conf['awssdk2_access_key'] = 'your_access_key_here';`
    * `$conf['awssdk2_secret_key'] = 'your_secret_key_here';`

5. Set AWS region variable (add to your `settings.php`):
    * `$conf['s3fs_region'] = 'your_region';`
