<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReverseShareInvite extends Model
{
  protected $fillable = [
    'user_id',
    'guest_user_id',
    'recipient_name',
    'recipient_email',
    'message',
    'expires_at',
    'completed_at',
    'password',
    'keep_files_days'
  ];

  // The hash of the password stays on the server
  protected $hidden = ['password'];

  protected $casts = [
    'used_at' => 'datetime',
    'completed_at' => 'datetime',
    'expires_at' => 'datetime',
    'keep_files_days' => 'integer',
  ];

  public function user()
  {
    return $this->belongsTo(User::class);
  }

  public function guestUser()
  {
    return $this->belongsTo(User::class, 'guest_user_id');
  }

  // A password the member put on the request: the guest gives it before the link opens
  public function isProtected(): bool
  {
    return !empty($this->password);
  }

  // How long the files a guest drops stay online: what the member chose, else the default
  // lifetime of a share, never past the longest one the administration allows
  public function keepFilesDays(): int
  {
    $settings = app(\App\Services\SettingsService::class);
    $days = $this->keep_files_days ?: $settings->positiveInt('default_expiry_time', 7);
    $max = $settings->positiveInt('max_expiry_time', null);

    return $max !== null ? min($days, $max) : $days;
  }

  public function isExpired()
  {
    return $this->expires_at && now()->gt($this->expires_at);
  }

  public function markAsUsed()
  {
    $this->used_at = now();
    $this->save();
  }

  public function isUsed()
  {
    return $this->used_at !== null;
  }

  public function isCompleted()
  {
    return $this->completed_at !== null;
  }

  public function markAsCompleted()
  {
    $this->completed_at = now();
    $this->save();
  }
}
