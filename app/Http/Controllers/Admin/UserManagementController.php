<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Services\AdminUserManagement;
use Illuminate\Http\Request;

class UserManagementController extends AdminController
{
    public function index(Request $request)
    {
        $this->ensureAdmin($request);

        return view('admin.users.index');
    }

    public function show(Request $request, User $user)
    {
        $this->ensureAdmin($request);

        return view('admin.users.show', compact('user'));
    }

    public function store(Request $request, AdminUserManagement $manager)
    {
        $manager->create($request->user(), $request->all());

        return back()->with('status', 'Benutzer angelegt.');
    }

    public function update(Request $request, User $user, AdminUserManagement $manager)
    {
        $manager->update($request->user(), $user->id, $request->all());

        return back()->with('status', 'Benutzer aktualisiert. Die Sperre gilt auch für alle Geräte-Schlüssel.');
    }
}
