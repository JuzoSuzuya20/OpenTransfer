<?php

namespace App\AuthProviders;

use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

/**
 * Keycloak speaks OpenID Connect: this is the OpenID Connect provider with the values an admin
 * reads in the Keycloak console. The issuer is derived from the address of the server and the realm.
 */
class KeycloakAuthProvider extends OIDCAuthProvider
{
  public static function issuer(object $config): ?string
  {
    if (empty($config->server_url) || empty($config->realm)) {
      return null;
    }

    return rtrim($config->server_url, '/') . '/realms/' . rawurlencode($config->realm);
  }

  public static function getIcon(): string
  {
    // A key, drawn for this button: not the artwork of the Keycloak project
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 20 3"/><path d="m16 7 3 3"/><path d="m18 5 2 2"/></svg>';
  }

  public static function getName(): string
  {
    return 'Keycloak';
  }

  public static function getDescription(): string
  {
    return 'Sign in with an account of a Keycloak realm. In Keycloak, create an OpenID Connect client with client authentication turned on, and declare the callback URL of this provider as a valid redirect URI.';
  }

  public static function getValidator(array $data): Validator
  {
    return ValidatorFacade::make($data, [
      'server_url' => ['required', 'url:http,https'],
      // A realm name, not a path: the issuer is built from it
      'realm' => ['required', 'string', 'regex:/^[^\/?#]+$/'],
      'client_id' => ['required', 'string'],
      'client_secret' => ['required', 'string'],
      'logout_at_provider' => ['sometimes', 'boolean'],
    ]);
  }

  public static function getInformationUrl(): ?string
  {
    return 'https://www.keycloak.org/docs/latest/server_admin/#_oidc_clients';
  }

  public static function getEmptyProviderConfig(): array
  {
    return [
      'server_url' => '',
      'realm' => '',
      'client_id' => '',
      'client_secret' => '',
      // Signing out of OpenTransfer also closes the Keycloak session
      'logout_at_provider' => true,
    ];
  }
}
