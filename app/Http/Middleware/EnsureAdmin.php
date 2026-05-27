<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->session()->get('admin_id')) {
            return redirect()->route('login')->with('intended', $request->fullUrl());
        }
        return $next($request);
    }
}
