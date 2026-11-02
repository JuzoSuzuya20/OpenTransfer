<?php

namespace App\Twig;

use App\Models\File;
use App\Models\ReverseShareInvite;
use App\Models\Share;
use App\Models\User;
use Carbon\CarbonInterface;
use Twig\Extension\SandboxExtension;
use Twig\Sandbox\SecurityPolicy;

/**
 * Twig only renders email templates, and admins can rewrite those from the
 * UI. Without a sandbox a template such as {{ ['id']|map('system') }} runs
 * shell commands, so every Twig render is sandboxed with an allowlist
 * derived from the bundled templates.
 *
 * SandboxExtension is final: AppServiceProvider binds it to this factory and
 * config/twigbridge.php loads it by class name.
 */
final class EmailSandbox
{
    public static function extension(): SandboxExtension
    {
        return new SandboxExtension(new SecurityPolicy(
            allowedTags: ['extends', 'block', 'if', 'for', 'set'],
            allowedFilters: [
                'default', 'escape', 'e', 'upper', 'lower', 'capitalize', 'title', 'trim', 'nl2br',
                'date', 'length', 'format', 'replace', 'striptags', 'number_format', 'round', 'abs',
                'join', 'first', 'last', 'url_encode',
            ],
            allowedMethods: [
                CarbonInterface::class => ['__tostring', 'format', 'diffforhumans', 'todatestring', 'todatetimestring'],
            ],
            allowedProperties: [
                Share::class => ['id', 'name', 'description', 'long_id', 'download_count', 'download_limit', 'file_count', 'size', 'expires_at', 'created_at', 'user', 'files'],
                User::class => ['name', 'first_name', 'email'],
                ReverseShareInvite::class => ['id', 'message', 'recipient_name', 'recipient_email', 'expires_at', 'user'],
                File::class => ['name', 'original_name', 'size', 'type', 'full_path'],
            ],
            allowedFunctions: ['block'],
            allowedTests: ['defined', 'empty', 'null', 'none', 'odd', 'even', 'same as', 'iterable', 'divisible by'],
        ), true);
    }
}
