<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Share;
use App\Jobs\sendEmail;
use App\Mail\shareDeletionWarningMail;
use App\Services\SettingsService;

class sendDeletionWarningEmails implements ShouldQueue
{
  use Queueable;

  /**
   * Create a new job instance.
   */
  public function __construct()
  {
    //
  }

  /**
   * Execute the job.
   */
  public function handle(): void
  {
    $settingsService = new SettingsService();

    $shouldSend = $settingsService->get('emails_share_deletion_warning_enabled') ?? true;

    if (!$shouldSend) {
      return;
    }

    // A cleared field means the default, not a crash every hour
    $deletion_warning_days = $settingsService->positiveInt('deletion_warning_days', 7);
    $clean_files_after_days = $settingsService->positiveInt('clean_files_after_days', 30);

    $shares = Share::where('expires_at', '<=', now()->subDays($clean_files_after_days - $deletion_warning_days))
      ->where('sent_deletion_warning', false)
      ->get();

    foreach ($shares as $share) {
      // Reverse-share uploads have no owner (user_id is null) and an owner can be
      // deleted: warn whoever invited the upload, else just mark the share processed.
      // Crashing here stopped the job before every later share, every hour.
      $recipient = $share->user ?? $share->invite?->user;
      if ($recipient) {
        sendEmail::dispatch($recipient->email, shareDeletionWarningMail::class, ['share' => $share]);
      }
      $share->sent_deletion_warning = true;
      $share->save();
    }
  }
}
