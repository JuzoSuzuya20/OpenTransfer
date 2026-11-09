<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Share;
use App\Services\StorageEncryption;
use Illuminate\Support\Facades\Log;

class CreateShareZip implements ShouldQueue
{
  use Queueable;

  /**
   * Create a new job instance.
   */
  public function __construct(public Share $share)
  {
    //
  }

  /**
   * Execute the job.
   */
  public function handle(): void
  {


    if ($this->share->user_id) {
      $user_folder = $this->share->user_id;
    } else {
      //grab the first segment of the path
      $user_folder = explode('/', $this->share->path)[0];
    }

    $encryption = app(StorageEncryption::class);
    if ($encryption->isMisconfigured()) {
      Log::critical('Encryption of stored files is on but STORAGE_ENCRYPTION_KEY is missing or invalid: share ' . $this->share->long_id . ' stays in plain text');
    }

    //just check that we've not already created the zip file
    $zipPath = storage_path('app/shares/' . $user_folder . '/' . $this->share->long_id . '.zip');
    if (file_exists($zipPath)) {
      return;
    }

    //if there is only one file just leave it alone and set the status to ready
    if ($this->share->file_count == 1) {
      if ($encryption->isEnabled()) {
        $this->encryptDirectory($encryption, storage_path('app/shares/' . $user_folder . '/' . $this->share->long_id));
      }
      $this->share->status = 'ready';
      $this->share->save();
      return;
    }

    try {
      $sourcePath = storage_path('app/shares/' . $user_folder . '/' . $this->share->long_id);
      $this->createZipFromDirectory($sourcePath, $zipPath);
      if ($encryption->isEnabled()) {
        $this->encrypt($encryption, $zipPath);
      }
      $this->share->status = 'ready';
      $this->share->save();
      $this->removeDirectory($sourcePath);
    } catch (\Exception $e) {
      $this->share->status = 'failed';
      $this->share->save();
      Log::error('Error creating share zip: ' . $e->getMessage());
    }
  }

  /**
   * On failure the file stays readable in plain text: `encrypt-existing-files`
   * can be run later, while a share that cannot be downloaded helps nobody.
   */
  private function encrypt(StorageEncryption $encryption, string $path): void
  {
    try {
      $encryption->encryptFile($path);
    } catch (\Throwable $e) {
      Log::critical('Could not encrypt share file ' . $path . ': ' . $e->getMessage());
    }
  }

  private function encryptDirectory(StorageEncryption $encryption, string $directory): void
  {
    if (!is_dir($directory)) {
      return;
    }

    $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
    foreach ($entries as $entry) {
      if ($entry->isFile() && !$entry->isLink()) {
        $this->encrypt($encryption, $entry->getPathname());
      }
    }
  }

  function createZipFromDirectory($sourcePath, $zipPath)
  {

    // Ensure the zip directory exists
    $zipDir = dirname($zipPath);
    if (!is_dir($zipDir)) {
      mkdir($zipDir, 0755, true);
    }

    // Built in PHP rather than by running `zip -r`, which follows symbolic
    // links and would archive whatever a link inside the share points to.
    $zip = new \ZipArchive();
    if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
      throw new \Exception('Failed to create zip file');
    }

    $root = realpath($sourcePath);
    if ($root === false) {
      throw new \Exception('Share directory not found');
    }

    $entries = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($entries as $entry) {
      if ($entry->isLink()) {
        continue;
      }
      $relativePath = substr($entry->getPathname(), strlen($root) + 1);
      if ($entry->isDir()) {
        $zip->addEmptyDir($relativePath);
      } else {
        $zip->addFile($entry->getPathname(), $relativePath);
      }
    }

    if (!$zip->close()) {
      throw new \Exception('Failed to write zip file');
    }

    //check the zip file is valid
    if (!file_exists($zipPath)) {
      throw new \Exception('The zip operation completed but the zip file was not created');
    }

    //check the zip file is not empty
    if (filesize($zipPath) === 0) {
      throw new \Exception('The zip operation completed but the zip file was empty');
    }

    return true;
  }

  private function removeDirectory($dir)
  {
    if (!file_exists($dir) && !is_link($dir)) {
      return true;
    }

    // A link is removed itself, never followed into its target
    if (is_link($dir) || !is_dir($dir)) {
      return unlink($dir);
    }

    foreach (scandir($dir) as $item) {
      if ($item == '.' || $item == '..') {
        continue;
      }

      if (!$this->removeDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
        return false;
      }
    }

    return rmdir($dir);
  }
}
