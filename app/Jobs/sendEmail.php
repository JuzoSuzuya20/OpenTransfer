<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\EmailConfigService;
use Illuminate\Support\Facades\Log;
use Illuminate\Mail\Mailable;
class sendEmail implements ShouldQueue
{
    use Queueable;
    public $to;
    public $mailableClass;
    public $mailableData;
    /**
     * Create a new job instance.
     */
    public function __construct(String $to, String $mailableClass, $mailableData = [])
    {
        $this->to = $to;
        $this->mailableClass = $mailableClass;
        $this->mailableData = $mailableData;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $emailService = new EmailConfigService();

        // Not an error to retry three times with a stack trace: the administrator has not set up
        // the mail server yet. One line says which e-mail was set aside, and why.
        if ($reason = $emailService->missingConfiguration()) {
            Log::warning("E-mail not sent, {$reason}: set up the mail server under Administration", [
                'mail' => class_basename($this->mailableClass),
            ]);
            return;
        }

        $mailer = $emailService->configureMailer();
        $mailable = new $this->mailableClass(...$this->mailableData);
        $mailable = $emailService->configureMailable($mailable);
        $mailer->to($this->to)->send($mailable);
    }
}
