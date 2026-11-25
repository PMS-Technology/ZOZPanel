<?php

namespace Everest\Http\Controllers\Auth\Modules;

use Everest\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\RedirectResponse;
use Everest\Exceptions\DisplayException;
use Everest\Services\Auth\OpenIdService;
use Everest\Http\Controllers\Auth\AbstractLoginController;

class OpenIdLoginController extends AbstractLoginController
{
    public function requestToken(Request $request, string $provider, OpenIdService $oidc): string
    {
        $this->assertEnabled($provider);
        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);
            $this->sendLockoutResponse($request);
        }
        $issuer = $this->issuer($provider);
        $metadata = $oidc->discovery($issuer);
        $state = $provider . '-' . Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put('openid.' . $provider, compact('state', 'nonce', 'verifier', 'issuer'));

        return $metadata['authorization_endpoint'] . (str_contains($metadata['authorization_endpoint'], '?') ? '&' : '?') . http_build_query([
            'response_type' => 'code',
            'client_id' => config("modules.auth.$provider.client_id"),
            'redirect_uri' => route('auth.modules.openid.authenticate', ['provider' => $provider]),
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ]);
    }

    public function authenticate(Request $request, string $provider, OpenIdService $oidc): RedirectResponse
    {
        $this->assertEnabled($provider);
        $flow = $request->session()->pull('openid.' . $provider);
        if (!is_array($flow) || !is_string($request->query('state')) || !hash_equals($flow['state'], $request->query('state')) || !is_string($request->query('code')) || $flow['issuer'] !== $this->issuer($provider)) {
            throw new DisplayException('This login request is invalid or has expired, please try again.');
        }
        try {
            $metadata = $oidc->discovery($flow['issuer']);
            $token = Http::acceptJson()->asForm()->timeout(10)->withOptions(['allow_redirects' => false])->post($metadata['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'client_id' => config("modules.auth.$provider.client_id"),
                'client_secret' => config("modules.auth.$provider.client_secret"),
                'redirect_uri' => route('auth.modules.openid.authenticate', ['provider' => $provider]),
                'code' => $request->query('code'),
                'code_verifier' => $flow['verifier'],
            ])->throw()->json();
            if (!is_array($token) || !is_string($token['id_token'] ?? null)) {
                throw new \RuntimeException('Missing ID token.');
            }
            $email = $oidc->email($metadata, (string) config("modules.auth.$provider.client_id"), $token['id_token'], $flow['nonce'], $token['access_token'] ?? null);
        } catch (\Throwable $e) {
            throw new DisplayException('OpenID Connect authentication failed. Please check the provider configuration and verified email.');
        }
        $user = User::where('email', $email)->first();
        if ($user) {
            return $this->completeOAuthLogin($user, $request, '/');
        }
        $user = $this->createAccount(['email' => $email, 'username' => 'null_user_' . Str::random(16)], $request);

        return $this->completeOAuthLogin($user, $request, '/account/setup');
    }

    private function assertEnabled(string $provider): void
    {
        if (!in_array($provider, ['microsoft', 'oidc'], true) || !config("modules.auth.$provider.enabled")) {
            throw new DisplayException('This login provider is not enabled.');
        }
    }

    private function issuer(string $provider): string
    {
        if ($provider === 'microsoft') {
            $tenant = (string) config('modules.auth.microsoft.tenant');
            if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $tenant)) {
                throw new DisplayException('Configure a Microsoft tenant ID (UUID).');
            }

            return "https://login.microsoftonline.com/$tenant/v2.0";
        }

        return (string) config('modules.auth.oidc.issuer');
    }
}
