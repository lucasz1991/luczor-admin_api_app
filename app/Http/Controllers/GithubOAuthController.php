<?php

namespace App\Http\Controllers;

use App\Models\OAuthConnection;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class GithubOAuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('github')->scopes(['repo', 'admin:repo_hook', 'read:user'])->redirect();
    }

    public function callback(Request $request)
    {
        $githubUser = Socialite::driver('github')->user();
        OAuthConnection::updateOrCreate(
            ['user_id' => $request->user()->id, 'provider' => 'github'],
            [
                'provider_user_id' => (string) $githubUser->getId(),
                'access_token' => $githubUser->token,
                'refresh_token' => $githubUser->refreshToken ?? null,
                'expires_at' => isset($githubUser->expiresIn) ? now()->addSeconds((int) $githubUser->expiresIn) : null,
                'scopes' => ['repo', 'admin:repo_hook', 'read:user'],
                'meta' => ['nickname' => $githubUser->getNickname(), 'name' => $githubUser->getName()],
            ]
        );

        return redirect()->route('dashboard')->with('status', 'GitHub verbunden.');
    }
}
