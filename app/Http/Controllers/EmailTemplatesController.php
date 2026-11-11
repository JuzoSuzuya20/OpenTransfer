<?php

namespace App\Http\Controllers;

use App\Mail\Concerns\EmailTexts;
use App\Models\Setting;
use App\Support\Locales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The emails, one language at a time. The shipped version of each language lives in
 * resources/views/emails/{locale}/; what an admin writes for a language goes to
 * storage/templates/emails/{locale}/ and can be reset. A template an admin wrote before emails
 * had languages (storage/templates/emails/) still serves the default language.
 */
class EmailTemplatesController extends Controller
{
  private const BLOCKS = ['subject', 'header', 'content', 'action_url', 'action_text'];

  public function index(Request $request)
  {
    $locale = $request->query('locale', Locales::default());
    if (!Locales::isSupported($locale)) {
      return $this->invalidLocale();
    }

    $templates = [];
    foreach ($this->names() as $name) {
      $shippedBlocks = $this->blocks(file_get_contents($this->shippedPath($locale, $name)));
      [$source, $customised, $legacy] = $this->source($locale, $name);
      $blocks = $this->blocks($source);

      $templates[] = [
        'name' => preg_replace('/V\d+$/', '', $name),
        'id' => $name,
        'content' => $source,
        // The subject of a template written before languages was a setting
        'subject' => $legacy ? (string) Setting::where('key', "email_subject_{$name}.twig")->value('value') : ($blocks['subject'] ?? ''),
        'variables' => array_diff_key($blocks, ['subject' => true]),
        'requiredVariables' => array_keys($shippedBlocks),
        'customised' => $customised,
      ];
    }

    return response()->json([
      'status' => 'success',
      'message' => 'Email templates fetched successfully',
      'data' => ['locale' => $locale, 'templates' => $templates],
    ]);
  }

  public function update(Request $request)
  {
    $locale = $request->input('locale');
    if (!Locales::isSupported($locale)) {
      return $this->invalidLocale();
    }

    $templates = (array) $request->input('templates', []);
    foreach ($templates as $template) {
      $name = $template['id'] ?? '';
      if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
        return response()->json(['status' => 'error', 'message' => 'Invalid template ID', 'data' => []], 400);
      }
      if (!in_array($name, $this->names(), true)) {
        return response()->json(['status' => 'error', 'message' => 'Template not found: ' . $name, 'data' => []], 404);
      }

      // Every block the shipped email has must still be there: no email without its link
      $rules = ['subject' => ['required', 'string']];
      foreach (array_keys($this->blocks(file_get_contents($this->shippedPath($locale, $name)))) as $block) {
        if ($block !== 'subject') {
          $rules["variables.$block"] = ['required', 'string'];
        }
      }
      $validator = Validator::make($template, $rules);
      if ($validator->fails()) {
        return response()->json(['status' => 'error', 'message' => 'Invalid template', 'data' => $validator->errors()], 422);
      }
    }

    foreach ($templates as $template) {
      $this->write($locale, $template);
    }

    return response()->json(['status' => 'success', 'message' => 'Email templates updated successfully', 'data' => $templates]);
  }

  // Back to the shipped version of that language
  public function reset(string $locale, string $name)
  {
    if (!Locales::isSupported($locale)) {
      return $this->invalidLocale();
    }
    if (!in_array($name, $this->names(), true)) {
      return response()->json(['status' => 'error', 'message' => 'Template not found', 'data' => []], 404);
    }

    @unlink($this->rewrittenPath($locale, $name));
    if ($locale === Locales::default()) {
      // The template and subject written before languages served this language: they go too
      @unlink($this->legacyPath($name));
      $shipped = EmailTexts::shippedSubject($name);
      if ($shipped !== null) {
        Setting::where('key', "email_subject_{$name}.twig")->update(['value' => $shipped]);
      }
    }

    return response()->json(['status' => 'success', 'message' => 'Email template reset']);
  }

  /** @return string[] the emails, named after their shipped English templates */
  private function names(): array
  {
    $names = array_map(fn ($path) => basename($path, '.twig'), glob(resource_path('views/emails/en/*.twig')));
    sort($names);

    return $names;
  }

  /** @return array{0: string, 1: bool, 2: bool} the template, whether an admin wrote it, whether it predates languages */
  private function source(string $locale, string $name): array
  {
    if (file_exists($this->rewrittenPath($locale, $name))) {
      return [file_get_contents($this->rewrittenPath($locale, $name)), true, false];
    }
    if ($locale === Locales::default() && file_exists($this->legacyPath($name))) {
      return [file_get_contents($this->legacyPath($name)), true, true];
    }

    return [file_get_contents($this->shippedPath($locale, $name)), false, false];
  }

  private function write(string $locale, array $template): void
  {
    $path = $this->rewrittenPath($locale, $template['id']);
    if (!is_dir(dirname($path))) {
      mkdir(dirname($path), 0755, true);
    }

    $twig = "{% extends 'emails/layout' %}\n\n{% block subject %}{$template['subject']}{% endblock %}\n";
    foreach (['header', 'content', 'action_url', 'action_text'] as $block) {
      if (isset($template['variables'][$block])) {
        $twig .= "\n{% block {$block} %}{$template['variables'][$block]}{% endblock %}\n";
      }
    }
    $twig .= "\n{# written by an admin on " . date('Y-m-d H:i:s') . " #}\n";

    file_put_contents($path, $twig);
  }

  /** @return array<string, string> */
  private function blocks(string $content): array
  {
    preg_match_all('/{% block (\w+) %}(.*?){% endblock %}/s', $content, $matches);
    $blocks = [];
    foreach ($matches[1] as $index => $block) {
      if (in_array($block, self::BLOCKS, true)) {
        $blocks[$block] = $block === 'content' ? trim($matches[2][$index], "\n") : trim(str_replace("\n", '', $matches[2][$index]));
      }
    }

    return $blocks;
  }

  private function shippedPath(string $locale, string $name): string
  {
    $path = resource_path("views/emails/{$locale}/{$name}.twig");

    return file_exists($path) ? $path : resource_path("views/emails/en/{$name}.twig");
  }

  private function rewrittenPath(string $locale, string $name): string
  {
    return storage_path("templates/emails/{$locale}/{$name}.twig");
  }

  private function legacyPath(string $name): string
  {
    return storage_path("templates/emails/{$name}.twig");
  }

  private function invalidLocale()
  {
    return response()->json(['status' => 'error', 'message' => 'Unknown language', 'data' => ['supported' => Locales::SUPPORTED]], 422);
  }
}
