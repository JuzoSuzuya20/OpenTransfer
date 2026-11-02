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
