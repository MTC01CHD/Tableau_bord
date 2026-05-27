<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Identifiants invalides.']);
        }

        $request->session()->regenerate();
        $request->session()->put('admin_id', $user->id);
        $request->session()->put('admin_name', $user->name);

        return redirect($request->session()->pull('intended', route('admin.hfsql.edit')));
    }

    public function logout(Request $request)
    {
        $request->session()->flush();
        $request->session()->regenerate();
        return redirect()->route('login');
    }
}
