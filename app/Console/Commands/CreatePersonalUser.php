<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreatePersonalUser extends Command
{
    protected $signature = 'luczor:user:create {email} {--name=Luczor User}';

    protected $description = 'Create a normal personal account without changing existing users or administrator credentials.';

    public function handle(): int
    {
        $data = ['email' => $this->argument('email'), 'name' => $this->option('name')];
        Validator::make($data, ['email' => 'required|email|max:255', 'name' => 'required|string|max:160'])->validate();
        $created = DB::transaction(function () use ($data) {
            if (User::where('email', $data['email'])->exists()) {
                return false;
            }
            $tenant = Tenant::firstOrCreate(['slug' => 'luczor-system'], ['name' => 'Luczor System', 'plan' => 'internal', 'status' => 'active']);
            $user = User::create($data + ['tenant_id' => $tenant->id, 'role' => 'user', 'status' => true, 'password' => Str::random(64)]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return true;
        });
        $this->info($created ? 'Personal account created. Set a password through the existing password-reset page. No email was sent by this command.' : 'Account already exists; no changes made.');

        return self::SUCCESS;
    }
}
