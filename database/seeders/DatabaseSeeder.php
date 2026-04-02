<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            WorkflowSeeder::class,
            FormSeeder::class,
        ]);

        $admin = User::updateOrCreate([
            'email' => 'admin@gesit.com',
        ], [
            'name' => 'GESIT Admin',
            'password' => Hash::make('admin123'),
            'department' => 'Management System',
            'employee_id' => 'ADM-001',
        ]);
        $admin->syncRoles(['Admin']);

        $employee = User::updateOrCreate([
            'email' => 'employee@gesit.com',
        ], [
            'name' => 'GESIT Employee',
            'password' => Hash::make('employee123'),
            'department' => 'General',
            'employee_id' => 'EMP-001',
        ]);
        $employee->syncRoles(['Employee']);

        $itStaff = User::updateOrCreate([
            'email' => 'it@gesit.com',
        ], [
            'name' => 'GESIT IT Staff',
            'password' => Hash::make('it123456'),
            'department' => 'IT',
            'employee_id' => 'IT-001',
        ]);
        $itStaff->syncRoles(['IT Staff']);

        $director = User::updateOrCreate([
            'email' => 'director@gesit.com',
        ], [
            'name' => 'GESIT Operational Director',
            'password' => Hash::make('director123'),
            'department' => 'Operational',
            'employee_id' => 'DIR-001',
        ]);
        $director->syncRoles(['Operational Director']);

        $accounting = User::updateOrCreate([
            'email' => 'accounting@gesit.com',
        ], [
            'name' => 'GESIT Accounting',
            'password' => Hash::make('accounting123'),
            'department' => 'Finance',
            'employee_id' => 'ACC-001',
        ]);
        $accounting->syncRoles(['Accounting']);
    }
}
