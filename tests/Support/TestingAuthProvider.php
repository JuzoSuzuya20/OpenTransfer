<?php

namespace App\AuthProviders;

/**
 * Fournisseur d'identité factice pour les tests : ExternalAuthController
 * résout `App\AuthProviders\{provider_class}AuthProvider`, d'où l'espace de noms.
 */
class TestingAuthProvider extends BaseAuthProvider
{
    public const AUTHORIZE_URL = 'https://idp.example.test/authorize';

    /** Identité renvoyée au callback ; null simule un échec côté fournisseur. */
    public static ?AuthProviderUser $nextUser = null;

    public const LOGOUT_URL = 'https://idp.example.test/logout';

    /** Whether this provider ends its own session when OpenTransfer signs out. */
    public static bool $endsSession = false;

    public function logoutUrl(?string $idToken, string $redirectTo): ?string
    {
        return static::$endsSession
            ? self::LOGOUT_URL.'?'.http_build_query(['id_token_hint' => $idToken, 'post_logout_redirect_uri' => $redirectTo])
            : null;
    }

    public function redirect()
    {
        return redirect()->away(self::AUTHORIZE_URL);
    }

    public function handleCallback(): AuthProviderUser
    {
        if (!static::$nextUser) {
            $this->throwAuthFailureException();
        }

        return static::$nextUser;
    }
}
