<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Webkul\Installer\Database\Seeders\DatabaseSeeder as KrayinDatabaseSeeder;
use Webkul\Installer\Helpers\DatabaseManager;

/**
 * Non-interactive replacement for the web installer, meant for container deploys.
 *
 * Idempotent: on a fresh database it rebuilds the schema, seeds and creates the admin from ADMIN_EMAIL /
 * ADMIN_PASSWORD, then records the installation as complete. On an installed database it only
 * runs pending migrations.
 */
class DeploySetup extends Command
{
    protected $signature = 'crm:deploy-setup';

    protected $description = 'Migrate, seed and create the admin user without the web installer.';

    public function handle(DatabaseManager $databaseManager): int
    {
        if (! File::exists(base_path('.env'))) {
            File::put(base_path('.env'), '');
        }

        $installed = Schema::hasTable('users') && DB::table('users')->exists();

        if ($installed) {
            $this->call('migrate', ['--force' => true]);

            $this->markInstalled($databaseManager);

            $this->info('Already installed, migrations are up to date.');

            return self::SUCCESS;
        }

        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password || strlen($password) < 8) {
            $this->error('Set ADMIN_EMAIL and ADMIN_PASSWORD (min 8 characters) to create the first admin.');

            return self::FAILURE;
        }

        // No admin yet, so any existing tables come from an aborted install: start clean.
        $this->call('migrate:fresh', ['--force' => true]);

        $this->info('Seeding database...');

        app(KrayinDatabaseSeeder::class)->run([
            'locale' => config('app.locale', 'en'),
            'currency' => env('APP_CURRENCY', 'USD'),
        ]);

        DB::table('users')->updateOrInsert(
            ['id' => 1],
            [
                'name' => env('ADMIN_NAME', 'Admin'),
                'email' => $email,
                'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]),
                'role_id' => 1,
                'status' => 1,
            ]
        );

        $this->markInstalled($databaseManager);

        Artisan::call('storage:link');

        $this->info("Installed. Admin: {$email}");

        return self::SUCCESS;
    }

    protected function markInstalled(DatabaseManager $databaseManager): void
    {
        File::put(storage_path('installed'), 'Krayin is successfully installed');

        $databaseManager->markInstallationCompleted();
    }
}
