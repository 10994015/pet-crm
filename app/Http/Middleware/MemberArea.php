<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Pages only a signed-in owner sees. A membership the shop closed ends the session too. */
class MemberArea
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('member');

        if ($guard->check() && ! $guard->user()->isMember()) {
            $guard->logout();
        }

        if (! $guard->check()) {
            return redirect()->guest(route('front.member.login'));
        }

        return $next($request);
    }
}
