<?php

namespace App\AuthProviders;

use App\Models\AuthProvider as AuthProviderModel;
use Jumbojett\OpenIDConnectClient;
use App\AuthProviders\AuthProviderUser;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;
use App\AuthProviders\overrides\ErugoOpenIDConnectclient;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cookie;

class OIDCAuthProvider extends BaseAuthProvider
{

  protected $client_id;
  protected $client_secret;
  protected $base_url;
  protected $provider;

  public function __construct(AuthProviderModel $provider)
  {
    $this->client_id = $provider->provider_config->client_id ?? null;
    $this->client_secret = $provider->provider_config->client_secret ?? null;
    $this->base_url = static::issuer($provider->provider_config);
    $this->provider = $provider;
  }

  // The address the provider publishes its configuration under, at /.well-known/openid-configuration
  public static function issuer(object $config): ?string
  {
    return $config->base_url ?? null;
  }

  /**
   * The person the provider describes. Not every provider sends a name: Keycloak leaves it out when
   * the first and last names are empty, so the account falls back on the username, then the address.
   */
  public static function userFromClaims(object $claims): AuthProviderUser
  {
    $fullName = trim((($claims->given_name ?? '') . ' ' . ($claims->family_name ?? '')));

    return new AuthProviderUser([
      'sub' => $claims->sub ?? null,
      'name' => ($claims->name ?? null) ?: ($fullName ?: (($claims->preferred_username ?? null) ?: ($claims->email ?? null))),
      'email' => $claims->email ?? null,
      'avatar' => $claims->picture ?? null,
      'verified' => (bool) ($claims->email_verified ?? false),
    ]);
  }

  private function createClient()
  {

    //let's check we have all the required data
    if (!$this->client_id || !$this->client_secret || !$this->base_url) {
      $this->throwMissingDataException();
    }

    $client =  new ErugoOpenIDConnectclient(
      $this->base_url,
      $this->client_id,
      $this->client_secret
    );

    // Set callback URL and required scopes
    $route = route('social.provider.callback', ['provider' => $this->provider->uuid]);
    $client->setRedirectURL($route);
    $client->addScope(['openid', 'email', 'profile']);

    return $client;
  }

  public function redirect()
  {
    // Store the linking data in an encrypted cookie
    $linkingData = [
      'linkingAccount' => session('linkingAccount'),
      'linkingUserId' => session('linkingUserId')
    ];
    session_start();
    $_SESSION['oidc_linking_data'] = $linkingData;


    // Create OIDC client
    $oidc = $this->createClient();

    // Begin authentication flow
    $oidc->authenticate();

    $this->throwAuthFailureException();
  }

  public function handleCallback(): AuthProviderUser
  {
    session_start();
    $linkingData = $_SESSION['oidc_linking_data'];

    \Log::info("Linking data: " . json_encode($linkingData));

    if (isset($linkingData['linkingAccount']) && isset($linkingData['linkingUserId'])) {
      session(['linkingAccount' => $linkingData['linkingAccount']]);
      session(['linkingUserId' => $linkingData['linkingUserId']]);
    }

    // Create OIDC client and complete authentication
    $oidc = $this->createClient();
    $oidc->authenticate();

    // Get user info
    $userInfo = $oidc->requestUserInfo();


    $user = static::userFromClaims($userInfo);
    $user->idToken = $oidc->getIdToken() ?: null;

    return $user;
  }

  // RP-initiated logout, when the admin turned it on and the provider publishes an end session endpoint
  public function logoutUrl(?string $idToken, string $redirectTo): ?string
  {
    if (empty($this->provider->provider_config->logout_at_provider) || !$this->base_url) {
      return null;
    }

    try {
      $response = \Illuminate\Support\Facades\Http::timeout(3)->withoutRedirecting()->acceptJson()
        ->get(rtrim($this->base_url, '/') . '/.well-known/openid-configuration');
    } catch (\Throwable) {
      return null;
    }

    $endpoint = $response->successful() ? $response->json('end_session_endpoint') : null;
    if (!is_string($endpoint) || !in_array(parse_url($endpoint, PHP_URL_SCHEME), ['http', 'https'], true)) {
      return null;
    }

    $query = array_filter([
      'client_id' => $this->client_id,
      'post_logout_redirect_uri' => $redirectTo,
      'id_token_hint' => $idToken,
    ]);

    return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($query);
  }


  public static function getIcon(): string
  {
    return '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" viewBox="0 0 120 120"><path d="m 75.180374,15.11293 -15.99577,7.797938 0,79.945522 C 40.931432,100.568 27.193065,90.619126 27.193065,78.662788 c 0,-11.334002 12.358733,-20.879977 29.192279,-23.793706 l 0,-10.163979 C 30.637155,47.81728 11.197296,61.839238 11.197296,78.662788 c 0,17.429891 20.856984,31.825422 47.987308,34.224282 l 15.99577,-7.53134 0,-90.2428 z m 2.79926,29.592173 0,10.163979 c 6.261409,1.083679 11.913385,3.061436 16.528961,5.731817 l -8.664375,4.898704 30.95849,6.731553 -2.23275,-22.927269 -8.23115,4.632108 C 98.692362,49.310409 88.899095,46.024898 77.979634,44.705103 z" /></svg>';
  }

  public static function getName(): string
  {
    return 'OpenID Connect';
  }

  public static function getDescription(): string
  {
    return 'OpenID Connect is a standard for authentication and authorization that allows users to sign in to your application using their Google, Microsoft, or other OpenID Connect-compatible accounts.';
  }

  public static function getValidator(array $data): Validator
  {
    return ValidatorFacade::make($data, [
      'client_id' => ['required', 'string'],
      'client_secret' => ['required', 'string'],
      'base_url' => ['required', 'url'],
    ]);
  }

  public static function getInformationUrl(): ?string
  {
    return 'https://openid.net/connect/';
  }

  public static function getEmptyProviderConfig(): array
  {
    return [
      'client_id' => '',
      'client_secret' => '',
      'base_url' => '',
    ];
  }
}
