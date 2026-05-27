<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class SuperAdminController extends Controller
{
    // ── Tenants ──────────────────────────────────────────────────────────

    public function tenantsIndex()
    {
        $tenants = Tenant::orderBy('name')->withCount('users')->get();
        // Stats par tenant : nb lignes HFSQL (utile pour audit).
        $rowsByTenant = DB::table('hfsql_raw_rows')
            ->select('tenant_id', DB::raw('COUNT(*) AS n'))
            ->groupBy('tenant_id')
            ->pluck('n', 'tenant_id');
        return view('super.tenants.index', compact('tenants', 'rowsByTenant'));
    }

    public function tenantsStore(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'slug' => 'required|string|max:64|alpha_dash|unique:tenants,slug',
        ]);
        $data['is_active'] = true;
        Tenant::create($data);
        return back()->with('status', "Tenant « {$data['name']} » créé.");
    }

    public function tenantsToggle(Tenant $tenant)
    {
        $tenant->update(['is_active' => !$tenant->is_active]);
        return back()->with('status', "Tenant « {$tenant->name} » " . ($tenant->is_active ? 'activé' : 'désactivé') . '.');
    }

    public function tenantsDestroy(Request $request, Tenant $tenant)
    {
        // Garde-fou : confirmation explicite via le slug dans le formulaire.
        $request->validate(['confirm_slug' => 'required|string|in:' . $tenant->slug]);
        $tenant->delete(); // cascade DB sur hfsql_*, platform_settings
        return redirect()->route('super.tenants.index')
            ->with('status', "Tenant « {$tenant->name} » supprimé (cascade).");
    }

    // ── Users ────────────────────────────────────────────────────────────

    public function usersIndex()
    {
        $users   = User::with('tenant')->orderBy('email')->get();
        $tenants = Tenant::where('is_active', true)->orderBy('name')->get();
        return view('super.users.index', compact('users', 'tenants'));
    }

    public function usersStore(Request $request)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:120',
            'email'          => 'required|email|unique:users,email',
            'password'       => 'required|string|min:8',
            'tenant_id'      => 'nullable|integer|exists:tenants,id',
            'is_super_admin' => 'sometimes|boolean',
        ]);
        $isSuper = (bool) ($data['is_super_admin'] ?? false);
        if (!$isSuper && empty($data['tenant_id'])) {
            return back()->withErrors(['tenant_id' => 'Un user non super-admin doit avoir un tenant.'])->withInput();
        }
        User::create([
            'name'           => $data['name'],
            'email'          => $data['email'],
            'password'       => Hash::make($data['password']),
            'tenant_id'      => $isSuper ? null : $data['tenant_id'],
            'is_super_admin' => $isSuper,
        ]);
        return back()->with('status', "Utilisateur « {$data['email']} » créé.");
    }

    public function usersDestroy(Request $request, User $user)
    {
        /** @var User $current */
        $current = $request->attributes->get('user');
        if ($user->id === $current->id) {
            return back()->withErrors(['user' => 'Vous ne pouvez pas supprimer votre propre compte.']);
        }
        $user->delete();
        return back()->with('status', 'Utilisateur supprimé.');
    }
}
