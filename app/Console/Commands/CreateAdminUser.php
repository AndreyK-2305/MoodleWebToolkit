<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    protected $signature = 'app:create-admin {--name=} {--email=} {--password=}';

    protected $description = 'Crea o habilita la cuenta administradora inicial';

    public function handle(): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Nombre completo'));
        $email = mb_strtolower((string) ($this->option('email') ?: $this->ask('Correo electrónico')));
        $existing = User::query()->where('email', $email)->first();
        $password = $this->option('password');

        if (! $existing && ! $password) {
            $password = $this->secret('Contraseña (mínimo 12 caracteres)');
        }

        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => [$existing && ! $password ? 'nullable' : 'required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        $attributes = [
            'name' => $name,
            'role' => UserRole::ADMIN,
            'is_active' => true,
            'must_change_password' => false,
            'email_verified_at' => now(),
        ];

        if ($password) {
            $attributes['password'] = $password;
        }

        User::query()->updateOrCreate(['email' => $email], $attributes);

        $this->components->info($existing ? 'Administrador actualizado.' : 'Administrador creado.');

        return self::SUCCESS;
    }
}
