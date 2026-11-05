<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Safe to run on every deploy (the compose webdav-migrate service does): only the very
        // first run creates the admin.
        if (DB::table('users')->exists()) {
            return;
        }

        if (empty(env('INITIAL_USER_EMAIL')) || empty(env('INITIAL_USER_PASSWORD'))) {
            $this->command?->warn('No users yet, and INITIAL_USER_EMAIL / INITIAL_USER_PASSWORD are not set: no admin created.');
            return;
        }

        DB::table('users')->insert([
            'name' => env('INITIAL_USER_NAME'),
            'email' => env('INITIAL_USER_EMAIL'),
            'password' => Hash::make(env('INITIAL_USER_PASSWORD')),
            'is_admin' => true,
        ]);
    }
}
