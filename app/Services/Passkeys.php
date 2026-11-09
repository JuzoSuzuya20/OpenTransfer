<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPasskey;
use Cose\Algorithms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * WebAuthn passkeys (web-auth/webauthn-lib), adapted from the
 * moutonnoireu/erugo-security fork.
 *
 * The relying party id is the host the browser uses to reach the application,
 * so passkeys are bound to that domain: the same host the SSO callback URLs
 * are built from. HTTPS is required, except on localhost.
 */
class Passkeys
{
    private const TIMEOUT_MS = 60000;
    private const OPTIONS_TTL = 300;

    private ?SerializerInterface $serializer = null;

    public function __construct(private Request $request, private SettingsService $settings)
    {
    }

    private function rpId(): string
    {
        return $this->request->getHost();
    }

    // ----- adding a passkey ----------------------------------------------

    public function registrationOptions(User $user): array
    {
        $exclude = $user->passkeys()->get()
            ->map(fn (UserPasskey $passkey) => $this->loadRecord($passkey)->getPublicKeyCredentialDescriptor())
            ->all();

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create($this->settings->get('application_name') ?: 'OpenTransfer', $this->rpId()),
            PublicKeyCredentialUserEntity::create($user->email, (string) $user->id, $user->name ?: $user->email),
            random_bytes(32),
            [
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256),
            ],
            AuthenticatorSelectionCriteria::create(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $exclude,
            self::TIMEOUT_MS,
        );

        return $this->remember($this->registrationKey($user), $options);
    }

    /**
     * Verifies what navigator.credentials.create() returned and stores the
     * passkey. Null when it does not hold.
     */
    public function register(User $user, array $credential, string $name): ?UserPasskey
    {
        try {
            /** @var PublicKeyCredentialCreationOptions|null $options */
            $options = $this->recall($this->registrationKey($user), PublicKeyCredentialCreationOptions::class);
            $response = $this->parseCredential($credential)->response;

            if (!$options || !$response instanceof AuthenticatorAttestationResponse) {
                return null;
            }

            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonies()->creationCeremony())
                ->check($response, $options, $this->rpId());
        } catch (\Throwable $e) {
            Log::warning('Passkey registration refused', ['reason' => $e->getMessage()]);

            return null;
        }

        $passkey = new UserPasskey(['name' => mb_substr($name, 0, 100) ?: 'Passkey']);
        $passkey->user_id = $user->id;
        $passkey->credential_hash = $this->credentialHash($record->publicKeyCredentialId);
        $passkey->credential_source = $this->serialize($record);
        $passkey->save();

        return $passkey;
    }

    // ----- as a second factor --------------------------------------------

    public function authenticationOptions(User $user, string $challengeToken): array
    {
        $allow = $user->passkeys()->get()
            ->map(fn (UserPasskey $passkey) => $this->loadRecord($passkey)->getPublicKeyCredentialDescriptor())
            ->all();

        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->rpId(),
            $allow,
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            self::TIMEOUT_MS,
        );

        return $this->remember($this->authenticationKey($challengeToken), $options);
    }

    public function verify(User $user, string $challengeToken, array $credential): bool
    {
        try {
            /** @var PublicKeyCredentialRequestOptions|null $options */
            $options = $this->recall($this->authenticationKey($challengeToken), PublicKeyCredentialRequestOptions::class);
            $parsed = $this->parseCredential($credential);
            $passkey = $user->passkeys()->where('credential_hash', $this->credentialHash($parsed->rawId))->first();

            if (!$options || !$passkey || !$parsed->response instanceof AuthenticatorAssertionResponse) {
                return false;
            }

            $this->checkAssertion($passkey, $parsed->response, $options);
        } catch (\Throwable $e) {
            Log::warning('Passkey verification refused', ['reason' => $e->getMessage()]);

            return false;
        }

        return true;
    }

    // ----- signing in without a password ---------------------------------

    /**
     * The browser offers the passkeys it holds for this site. User
     * verification is required: nothing else replaces the password here.
     */
    public function discoverableOptions(string $token): array
    {
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->rpId(),
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            self::TIMEOUT_MS,
        );

        return $this->remember($this->discoverableKey($token), $options);
    }

    /**
     * The owner of the passkey, once its signature holds.
     */
    public function verifyDiscoverable(string $token, array $credential): ?User
    {
        try {
            /** @var PublicKeyCredentialRequestOptions|null $options */
            $options = $this->recall($this->discoverableKey($token), PublicKeyCredentialRequestOptions::class);
            $parsed = $this->parseCredential($credential);
            $response = $parsed->response;

            // A discoverable credential must say which account it belongs to
            if (!$options || !$response instanceof AuthenticatorAssertionResponse || $response->userHandle === null) {
                return null;
            }

            $passkey = UserPasskey::where('credential_hash', $this->credentialHash($parsed->rawId))->first();
            if (!$passkey) {
                return null;
            }

            // Refuses a user handle that is not the owner of the passkey
            $this->checkAssertion($passkey, $response, $options);
        } catch (\Throwable $e) {
            Log::warning('Passkey sign-in refused', ['reason' => $e->getMessage()]);

            return null;
        }

        return $passkey->user;
    }

    // ----- shared --------------------------------------------------------

    private function checkAssertion(UserPasskey $passkey, AuthenticatorAssertionResponse $response, PublicKeyCredentialRequestOptions $options): void
    {
        $record = AuthenticatorAssertionResponseValidator::create($this->ceremonies()->requestCeremony())
            // The owner is known: browsers do not always send a user handle for a second factor
            ->check($this->loadRecord($passkey), $response, $options, $this->rpId(), (string) $passkey->user_id);

        // The new signature counter, against cloned authenticators
        $passkey->credential_source = $this->serialize($record);
        $passkey->last_used_at = now();
        $passkey->save();
    }

    private function remember(string $key, object $options): array
    {
        $json = $this->serialize($options);
        Cache::put($key, $json, self::OPTIONS_TTL);

        return json_decode($json, true);
    }

    /**
     * Options can be answered only once: a signature cannot be replayed.
     */
    private function recall(string $key, string $class): ?object
    {
        $json = Cache::pull($key);

        return is_string($json) ? $this->serializer()->deserialize($json, $class, 'json') : null;
    }

    private function parseCredential(array $credential): PublicKeyCredential
    {
        return $this->serializer()->deserialize(json_encode($credential), PublicKeyCredential::class, 'json');
    }

    private function loadRecord(UserPasskey $passkey): CredentialRecord
    {
        return $this->serializer()->deserialize($passkey->credential_source, CredentialRecord::class, 'json');
    }

    private function credentialHash(string $credentialId): string
    {
        return hash('sha256', $credentialId);
    }

    private function ceremonies(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        // Browsers only allow WebAuthn over plain HTTP on localhost
        $factory->setSecuredRelyingPartyId(['localhost']);

        return $factory;
    }

    private function serialize(object $value): string
    {
        return $this->serializer()->serialize($value, 'json', ['skip_null_values' => true]);
    }

    private function serializer(): SerializerInterface
    {
        return $this->serializer ??= (new WebauthnSerializerFactory(
            AttestationStatementSupportManager::create([NoneAttestationStatementSupport::create()])
        ))->create();
    }

    private function registrationKey(User $user): string
    {
        return 'passkey:register:'.$user->id;
    }

    private function authenticationKey(string $challengeToken): string
    {
        return 'passkey:login:'.hash('sha256', $challengeToken);
    }

    private function discoverableKey(string $token): string
    {
        return 'passkey:passwordless:'.hash('sha256', $token);
    }
}
