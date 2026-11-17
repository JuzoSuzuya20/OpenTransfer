<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Mailable;

class EmailConfigService
{

  private $settingService;
  private $settings;

  // The saved settings, or others to try (the SMTP test of Administration, before saving)
  public function __construct(?array $settings = null)
  {
    $this->settingService = new SettingsService();
    $this->settings = $settings ?? $this->settingService->getMailSettings();
  }

  public function settingsInUse(): array
  {
    return $this->settings;
  }

  /**
   * Why an e-mail cannot leave yet, or null when it can: until an administrator sets up the mail
   * server, the host is empty and the sender address may read "null" (the old interface saved
   * empty fields that way).
   */
  public function missingConfiguration(): ?string
  {
    $host = trim((string) $this->settings['host']);
    if ($host === '' || $host === 'null') {
      return 'no mail server is set up';
    }
    if (!filter_var((string) $this->settings['from_address'], FILTER_VALIDATE_EMAIL)) {
      return 'no valid sender address is set up';
    }

    return null;
  }

  // A timeout for a test that must answer soon; none for the e-mails the queue sends
  public function configureMailer(?int $timeout = null)
  {
    $settings = $this->settings;

    // Laravel's SMTP transport expects null for no encryption, not an empty string
    $encryption = $settings['encryption'];
    if ($encryption === '' || $encryption === null) {
      $encryption = null;
    }

    $config = [
      'transport' => 'smtp',
      'host' => $settings['host'],
      'port' => $settings['port'],
      'encryption' => $encryption,
      'timeout' => $timeout,
    ];

    // Only add authentication credentials if they are actually provided
    // This allows SMTP relays that don't require authentication to work
    // Note: We also check for the string "null" because the frontend converts null values to "null" strings
    $hasUsername = !empty($settings['username']) && $settings['username'] !== 'null';
    $hasPassword = !empty($settings['password']) && $settings['password'] !== 'null';
    if ($hasUsername && $hasPassword) {
      $config['username'] = $settings['username'];
      $config['password'] = $settings['password'];
    }

    // Set EHLO domain from the sender email address to avoid 'localhost' issues
    // with some SMTP relays (e.g., Gmail SMTP relay)
    if (!empty($settings['from_address']) && strpos($settings['from_address'], '@') !== false) {
      $config['local_domain'] = explode('@', $settings['from_address'])[1];
    }

    $mailer = Mail::build($config);

    return $mailer;
  }

  public function configureMailable(Mailable $mailable)
  {
    $mailable->from($this->settings['from_address'], $this->settings['from_name']);
    return $mailable;
  }
}
