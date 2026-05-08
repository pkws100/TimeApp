<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class DemoDataSeeder extends AbstractSeed
{
    public function run(): void
    {
        $users = [
            [
                'employee_number' => 'MA-0001',
                'first_name' => 'Claudia',
                'last_name' => 'Werner',
                'email' => 'admin@example.invalid',
                'phone' => '+49 171 10000001',
                'password_hash' => password_hash('secret123!', PASSWORD_DEFAULT),
                'employment_status' => 'active',
                'emergency_contact_name' => 'Peter Werner',
                'emergency_contact_phone' => '+49 171 20000001',
                'target_hours_month' => 173.33,
            ],
        ];

        $projects = [
            ['project_number' => '2026-001', 'name' => 'Neubau Kita Nord', 'customer_name' => 'Stadtwerke Nord', 'status' => 'active', 'city' => 'Hamburg'],
            ['project_number' => '2026-002', 'name' => 'Sanierung Rathaus', 'customer_name' => 'Gemeinde Mitte', 'status' => 'planning', 'city' => 'Lueneburg'],
        ];

        $assets = [
            ['asset_type' => 'vehicle', 'name' => 'Crafter 3.5t', 'identifier' => 'HH-ZE-501', 'status' => 'available'],
            ['asset_type' => 'equipment', 'name' => 'Minibagger 1.8t', 'identifier' => 'EQ-018', 'status' => 'available'],
        ];

        $this->table('users')->insert($users)->saveData();
        $this->table('projects')->insert($projects)->saveData();
        $this->table('assets')->insert($assets)->saveData();

        $this->execute(
            "INSERT IGNORE INTO user_roles (user_id, role_id)
             SELECT users.id, roles.id
             FROM users
             INNER JOIN roles ON roles.slug = 'administrator'
             WHERE users.email = 'admin@example.invalid'"
        );
    }
}
