<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
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

        // Super-admin : présélectionne le premier tenant actif si rien en session.
        // User normal : tenant fixe via son tenant_id (résolu par ResolveTenant).
        if ($user->isSuperAdmin()) {
            if (!$request->session()->get('current_tenant_id')) {
                $first = Tenant::where('is_active', true)->orderBy('name')->first();
                if ($first) {
                    $request->session()->put('current_tenant_id', $first->id);
                }
            }
            $redirect = $request->session()->pull('intended', route('super.tenants.index'));
        } else {
            $redirect = $request->session()->pull('intended', route('dashboard'));
        }

        return redirect($redirect);
    }

    public function logout(Request $request)
    {
        $request->session()->flush();
        $request->session()->regenerate();
        return redirect()->route('login');
    }

    /** Super-admin : change le tenant courant. */
    public function switchTenant(Request $request)
    {
        /** @var User $user */
        $user = $request->attributes->get('user');
        if (!$user->isSuperAdmin()) {
            abort(403);
        }
        $data = $request->validate(['tenant_id' => 'required|integer|exists:tenants,id']);
        $request->session()->put('current_tenant_id', $data['tenant_id']);
        return back()->with('status', 'Tenant courant changé.');
    }
}
