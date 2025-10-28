<?php

namespace App\Console\Commands;

use App\Models\WmsPicker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateTestPickerCommand extends Command
{
    protected $signature = 'wms:create-test-picker {--code=TEST001} {--name=テストピッカー} {--password=password123}';

    protected $description = 'Create a test picker user for API testing';

    public function handle(): int
    {
        $code = $this->option('code');
        $name = $this->option('name');
        $password = $this->option('password');

        // Check if picker already exists
        if (WmsPicker::where('code', $code)->exists()) {
            $this->error("Picker with code '{$code}' already exists.");

            if ($this->confirm('Do you want to update the password?')) {
                $picker = WmsPicker::where('code', $code)->first();
                $picker->update(['password' => Hash::make($password)]);
                $this->info("Password updated for picker '{$code}'");

                $this->newLine();
                $this->info('Test credentials:');
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['Code', $picker->code],
                        ['Name', $picker->name],
                        ['Password', $password],
                        ['Is Active', $picker->is_active ? 'Yes' : 'No'],
                    ]
                );

                return Command::SUCCESS;
            }

            return Command::FAILURE;
        }

        // Create new picker
        $picker = WmsPicker::create([
            'code' => $code,
            'name' => $name,
            'password' => Hash::make($password),
            'default_warehouse_id' => 991, // Default warehouse
            'is_active' => true,
        ]);

        $this->info("Test picker created successfully!");

        $this->newLine();
        $this->info('Test credentials:');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $picker->id],
                ['Code', $picker->code],
                ['Name', $picker->name],
                ['Password', $password],
                ['Default Warehouse ID', $picker->default_warehouse_id],
                ['Is Active', $picker->is_active ? 'Yes' : 'No'],
            ]
        );

        $this->newLine();
        $this->info('You can now use these credentials to test the login API:');
        $this->line('POST /api/auth/login');
        $this->line(json_encode([
            'code' => $code,
            'password' => $password,
        ], JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }
}
