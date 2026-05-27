<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Vérifie qu'un utilisateur est authentifié (session('admin_id')).
 * Charge le User et le met à dispo via $request->attributes->get('user').
 */
class AuthUser
{
    public function handle(Request $request, Closure $next)
    {
        $userId = $request->session()->get('admin_id');
        if (!$userId) {
            return redirect()->route('login')->with('intended', $request->fullUrl());
        }
        $user = User::find($userId);
        if (!$user) {
            $request->session()->flush();
            return redirect()->route('login');
        }
        $request->attributes->set('user', $user);
        return $next($request);
    }
}
