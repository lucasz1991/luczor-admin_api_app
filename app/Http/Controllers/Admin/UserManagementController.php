<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserManagementController extends AdminController
{
    public function store(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email', 'max:255', Rule::unique('users')], 'password' => ['required', 'confirmed', Password::min(12)]]);
        User::create($data + ['tenant_id' => $request->user()->tenant_id, 'role' => 'user', 'status' => true]);
        return back()->with('status', 'Benutzer angelegt.');
    }

    public function update(Request $request, User $user)
    {
        $this->ensureAdmin($request);
        abort_if($user->isAdmin(), 403, 'Admin-Konten werden über die bestehende Kontoverwaltung gepflegt.');
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)], 'status' => ['required', 'boolean']]);
        if ($data['email'] !== $user->email) $user->email_verified_at = null;
        $user->fill($data)->save();
        return back()->with('status', 'Benutzer aktualisiert. Die Sperre gilt auch für alle Geräte-Schlüssel.');
    }
}
