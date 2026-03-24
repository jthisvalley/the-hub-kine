<?php
// database/seeders/UserSeeder.php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin user
        User::create([
            'id' => Str::uuid(),
            'email' => 'admin@hub-kine.com',
            'password' => Hash::make('Admin123!'),
            'role' => 'admin',
            'first_name' => 'Alexandre',
            'last_name' => 'Martin',
            'phone' => '+33123456789',
            'avatar_url' => null,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // Create kines (physiotherapists)
        $kines = [
            [
                'id' => Str::uuid(),
                'email' => 'kine1@hub-kine.com',
                'password' => Hash::make('Kine123!'),
                'role' => 'kine',
                'first_name' => 'Sophie',
                'last_name' => 'Dubois',
                'phone' => '+33123456780',
                'avatar_url' => 'https://i.pravatar.cc/150?img=1',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'email' => 'kine2@hub-kine.com',
                'password' => Hash::make('Kine123!'),
                'role' => 'kine',
                'first_name' => 'Thomas',
                'last_name' => 'Leroy',
                'phone' => '+33123456781',
                'avatar_url' => 'https://i.pravatar.cc/150?img=2',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'email' => 'kine3@hub-kine.com',
                'password' => Hash::make('Kine123!'),
                'role' => 'kine',
                'first_name' => 'Marie',
                'last_name' => 'Bernard',
                'phone' => '+33123456782',
                'avatar_url' => 'https://i.pravatar.cc/150?img=3',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'email' => 'kine.inactive@hub-kine.com',
                'password' => Hash::make('Kine123!'),
                'role' => 'kine',
                'first_name' => 'Jean',
                'last_name' => 'Petit',
                'phone' => '+33123456783',
                'avatar_url' => 'https://i.pravatar.cc/150?img=4',
                'is_active' => false, // Inactive account
                'email_verified_at' => now(),
            ],
        ];

        foreach ($kines as $kine) {
            User::create($kine);
        }

        // Create patients
        $patients = [
            // Patients for Kine 1 (Sophie Dubois)
            [
                'id' => Str::uuid(),
                'email' => 'patient1@hub-kine.com',
                'password' => Hash::make('Patient123!'),
                'role' => 'patient',
                'first_name' => 'Luc',
                'last_name' => 'Moreau',
                'phone' => '+33123456790',
                'avatar_url' => 'https://i.pravatar.cc/150?img=5',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'email' => 'patient2@hub-kine.com',
                'password' => Hash::make('Patient123!'),
                'role' => 'patient',
                'first_name' => 'Emma',
                'last_name' => 'Garcia',
                'phone' => '+33123456791',
                'avatar_url' => 'https://i.pravatar.cc/150?img=6',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'email' => 'patient3@hub-kine.com',
                'password' => Hash::make('Patient123!'),
                'role' => 'patient',
                'first_name' => 'Nicolas',
                'last_name' => 'Rousseau',
                'phone' => '+33123456792',
                'avatar_url' => 'https://i.pravatar.cc/150?img=7',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
            // Patients for Kine 2 (Thomas Leroy)
            [
                'id' => Str::uuid(),
                'email' => 'patient4@hub-kine.com',
                'password' => Hash::make('Patient123!'),
                'role' => 'patient',
                'first_name' => 'Camille',
                'last_name' => 'Fournier',
                'phone' => '+33123456793',
                'avatar_url' => 'https://i.pravatar.cc/150?img=8',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'email' => 'patient5@hub-kine.com',
                'password' => Hash::make('Patient123!'),
                'role' => 'patient',
                'first_name' => 'Antoine',
                'last_name' => 'Lefevre',
                'phone' => '+33123456794',
                'avatar_url' => 'https://i.pravatar.cc/150?img=9',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'email' => 'patient.inactive@hub-kine.com',
                'password' => Hash::make('Patient123!'),
                'role' => 'patient',
                'first_name' => 'Claire',
                'last_name' => 'Mercier',
                'phone' => '+33123456795',
                'avatar_url' => 'https://i.pravatar.cc/150?img=10',
                'is_active' => false, // Inactive patient
                'email_verified_at' => now(),
            ],
            // Patient for Kine 3 (Marie Bernard)
            [
                'id' => Str::uuid(),
                'email' => 'patient6@hub-kine.com',
                'password' => Hash::make('Patient123!'),
                'role' => 'patient',
                'first_name' => 'Paul',
                'last_name' => 'Dubois',
                'phone' => '+33123456796',
                'avatar_url' => 'https://i.pravatar.cc/150?img=11',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
            // Patient without kine (for testing)
            [
                'id' => Str::uuid(),
                'email' => 'patient.no-kine@hub-kine.com',
                'password' => Hash::make('Patient123!'),
                'role' => 'patient',
                'first_name' => 'Sarah',
                'last_name' => 'Laurent',
                'phone' => '+33123456797',
                'avatar_url' => 'https://i.pravatar.cc/150?img=12',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        ];

        foreach ($patients as $patient) {
            User::create($patient);
        }

        // Create 10 more random patients for testing
        User::factory()->count(10)->create([
            'role' => 'patient',
            'is_active' => true,
        ]);

        // Create 2 more random kines
        User::factory()->count(2)->create([
            'role' => 'kine',
            'is_active' => true,
        ]);

        $this->command->info('✅ Users seeded successfully!');
        $this->command->info('👑 Admin: admin@hub-kine.com / Admin123!');
        $this->command->info('👨‍⚕️ Kine: kine1@hub-kine.com / Kine123!');
        $this->command->info('👨‍⚕️ Kine: kine2@hub-kine.com / Kine123!');
        $this->command->info('👤 Patient: patient1@hub-kine.com / Patient123!');
        $this->command->info('👤 Patient: patient4@hub-kine.com / Patient123!');
    }
}
