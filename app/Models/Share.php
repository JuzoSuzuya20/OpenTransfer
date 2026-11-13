<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Setting;
use App\Mail\shareDeletedWarningMail;
use App\Jobs\sendEmail;
use App\Services\SettingsService;
// The facade under another name: File is the model of the files of a share
use Illuminate\Support\Facades\File as Filesystem;
class Share extends Model
{

  protected $fillable = [
    'user_id',
    'name',
    'description',
    'path',
    'long_id',
    'size',
    'file_count',
    'download_limit',
    'download_count',
    'require_email',
    'expires_at',
    'status',
    'password'
  ];

  protected $casts = [
    'expires_at' => 'datetime',
    'deletes_at' => 'datetime',
  ];

  protected $appends = [
    'expired',
    'deletes_at',
    'deleted'
  ];

  protected $hidden = [
    'path',
    'user_id',
  ];

  public function files()
  {
    return $this->hasMany(File::class);
  }

  public function getFileCountAttribute()
  {
    return $this->files()->count();
  }

  public function user()
  {
    return $this->belongsTo(User::class);
  }

  public function invite()
  {
    return $this->belongsTo(ReverseShareInvite::class, 'invite_id');
  }

  function getExpiredAttribute()
  {
    return $this->expires_at < now()->addMinutes(1);
  }

  function getDeletesAtAttribute()
  {
    $cleanFilesAfterDays = Setting::where('key', 'clean_files_after_days')->first();

    if (!$cleanFilesAfterDays) {
      $cleanFilesAfterDays = 30;
    } else {
      $cleanFilesAfterDays = (int) $cleanFilesAfterDays->value;
    }

    if ($cleanFilesAfterDays == 0) {
      $cleanFilesAfterDays = 30;
    }

    return $this->expires_at->addDays($cleanFilesAfterDays);
  }

  function getDeletedAttribute()
  {
    return $this->status == 'deleted';
  }

  public function scopeReadyForCleaning($query)
  {
    $cleanFilesAfterDays = Setting::where('key', 'clean_files_after_days')->first();

    if (!$cleanFilesAfterDays) {
      $cleanFilesAfterDays = 30;
    } else {
      $cleanFilesAfterDays = (int) $cleanFilesAfterDays->value;
    }

    if ($cleanFilesAfterDays == 0) {
      $cleanFilesAfterDays = 30;
    }

    $deletesAt = now()->subDays($cleanFilesAfterDays);

    return $query->where('expires_at', '<', $deletesAt)->where('status', '!=', 'deleted');
  }


  public function cleanFiles($disableEmail = false)
  {
    try {
      // Never the whole folder of the shares: a share always has a path of its own
      $filePath = trim((string) $this->path, '/');
      if ($filePath === '' || str_contains($filePath, '..')) {
        return false;
      }
      $completePath = storage_path('app/shares/' . $filePath);

      // Subfolders included: a folder sent as it was keeps its tree on the disk
      if (is_dir($completePath)) {
        Filesystem::deleteDirectory($completePath);
      }
      if (is_file($completePath . '.zip')) {
        unlink($completePath . '.zip');
      }

      // Who is told: the owner, or for the files a guest dropped, the member who asked them
      $recipient = $this->user ?? $this->invite?->user;

      $this->status = 'deleted';
      $this->save();

      if ($this->invite) {
        $this->invite->delete();
      }

      $settingsService = new SettingsService();

      $shouldSend = $settingsService->get('emails_share_deleted_enabled') ?? true;

      if (!$disableEmail && $shouldSend && $recipient) {
        sendEmail::dispatch($recipient->email, shareDeletedWarningMail::class, ['share' => $this]);
      }

      return true;
    } catch (\Exception $e) {
      return false;
    }
  }
}
