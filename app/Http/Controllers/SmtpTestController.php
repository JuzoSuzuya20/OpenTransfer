<?php

namespace App\Http\Controllers;

use App\Mail\smtpTestMail;
use App\Services\EmailConfigService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Administration > Serveur d'e-mails: a test e-mail to the admin, with the values of the form,
 * saved or not, sent at once. Each field of the form counts as sent (an empty encryption is
 * "none"); only the password left empty is the one saved, which the screen never has.
 */
class SmtpTestController extends Controller
{
    private const FIELDS = [
        'smtp_host' => 'host',
        'smtp_port' => 'port',
        'smtp_username' => 'username',
        'smtp_encryption' => 'encryption',
        'smtp_sender_address' => 'from_address',
        'smtp_sender_name' => 'from_name',
    ];

    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:1024'],
            'smtp_encryption' => ['nullable', Rule::in(['', 'tls', 'ssl'])],
            'smtp_sender_address' => ['nullable', 'string', 'max:255'],
            'smtp_sender_name' => ['nullable', 'string', 'max:255'],
        ]);

        $saved = app(SettingsService::class)->getMailSettings();
        $settings = $saved;
        foreach (self::FIELDS as $field => $key) {
            if ($request->exists($field)) {
                $settings[$key] = $request->input($field) ?? '';
            }
        }
        $settings['password'] = filled($request->input('smtp_password')) ? (string) $request->input('smtp_password') : $saved['password'];

        $service = app()->makeWith(EmailConfigService::class, ['settings' => $settings]);
        if ($reason = $service->missingConfiguration()) {
            return response()->json(['status' => 'error', 'code' => 'smtp_not_configured', 'message' => ucfirst($reason)], 422);
        }

        $admin = $request->user();
        try {
            $mailer = $service->configureMailer(15);
            $mailer->to($admin->email)->send($service->configureMailable(new smtpTestMail($admin, (string) $settings['host'])));
        } catch (\Throwable $e) {
            $reason = $this->withoutSecret($e->getMessage(), (string) ($settings['password'] ?? ''));
            Log::warning('SMTP test failed', ['host' => $settings['host'], 'reason' => $reason]);

            return response()->json([
                'status' => 'error',
                'code' => 'smtp_test_failed',
                'message' => 'The test e-mail could not be sent',
                'data' => ['reason' => $reason],
            ], 422);
        }

        return response()->json(['status' => 'success', 'data' => ['sent_to' => $admin->email]]);
    }

    // What the server answered, the password taken out should it be repeated
    private function withoutSecret(string $message, string $password): string
    {
        if ($password !== '') {
            $message = str_replace([$password, base64_encode($password)], '••••', $message);
        }

        return mb_substr($message, 0, 500);
    }
}
