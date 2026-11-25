<?php

namespace Everest\Http\Controllers\Auth\Modules;

use Everest\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\RedirectResponse;
use Everest\Exceptions\DisplayException;
use Everest\Http\Controllers\Auth\AbstractLoginController;

class GithubLoginController extends AbstractLoginController
{
    public function requestToken(Request $request): string
    {
        $this->assertEnabled();
        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);
            $this->sendLockoutResponse($request);
        }

        $state = 'github-' . Str::random(40);
        $request->session()->put('github_oauth_state', $state);

        return 'https://github.com/login/oauth/authorize?' . http_build_query([
            'client_id' => config('modules.auth.github.client_id'),
            'redirect_uri' => route('auth.modules.github.authenticate'),
            'scope' => 'user:email',
            'state' => $state,
        ]);
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $this->assertEnabled();
        $state = $request->session()->pull('github_oauth_state');
        if (!is_string($state) || !is_string($request->query('state')) || !hash_equals($state, $request->query('state')) || !is_string($request->query('code'))) {
            throw new DisplayException('This GitHub login request is invalid or has expired, please try again.');
        }

        $token = Http::acceptJson()->asForm()->timeout(10)->post('https://github.com/login/oauth/access_token', [
            'client_id' => config('modules.auth.github.client_id'),
            'client_secret' => config('modules.auth.github.client_secret'),
            'redirect_uri' => route('auth.modules.github.authenticate'),
            'code' => $request->query('code'),
        ])->json('access_token');
        if (!is_string($token) || $token === '') {
            throw new DisplayException('GitHub authentication failed. Please try again.');
        }

        $response = Http::acceptJson()->withToken($token)->timeout(10)->get('https://api.github.com/user/emails');
        $emails = $response->successful() ? $response->json() : null;
        $email = null;
        if (is_array($emails)) {
            foreach ($emails as $entry) {
                if (is_array($entry) && ($entry['primary'] ?? false) === true && ($entry['verified'] ?? false) === true && is_string($entry['email'] ?? null)) {
                    $email = $entry['email'];
                    break;
                }
            }
        }
        if (!$email) {
            throw new DisplayException('Your GitHub account must have a verified primary email address.');
        }

        $user = User::where('email', $email)->first();
        if ($user) {
            return $this->completeOAuthLogin($user, $request, '/');
        }
        $user = $this->createAccount(['email' => $email, 'username' => 'null_user_' . Str::random(16)], $request);

        return $this->completeOAuthLogin($user, $request, '/account/setup');
    }

    private function assertEnabled(): void
    {
        if (!config('modules.auth.github.enabled')) {
            throw new DisplayException('GitHub login is not enabled on this Panel.');
        }
    }
}
