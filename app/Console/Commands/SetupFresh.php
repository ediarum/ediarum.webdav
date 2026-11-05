<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SetupFresh extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'setup:fresh {--reset : Remove all tables and start from scratch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up the application from scratch (database, migrations, seeds, keys). Use --reset to drop and recreate all tables.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting ediarum.WEBDAV setup...');

        // Step 0: Reset if requested
        if ($this->option('reset')) {
            $this->warn('WARNING: This will delete ALL data and recreate the database!');
            if (!$this->confirm('Do you want to continue?')) {
                $this->info('Setup cancelled.');
                return Command::FAILURE;
            }
            $this->info('Step 0: Dropping all tables and resetting migrations...');
            $this->call('migrate:fresh');
        }

        // Step 1: Generate APP_KEY if not set
        if (empty(config('app.key'))) {
            $this->info('Step 1: Generating APP_KEY...');
            $this->call('key:generate');
        } else {
            $this->info('Step 1: APP_KEY already exists, skipping...');
        }

        // Step 2: Create migrations table (if not already done)
        if (!$this->option('reset')) {
            $this->info('Step 2: Installing migrations table...');
            $this->call('migrate:install');

            // Step 3: Run migrations
            $this->info('Step 3: Running database migrations...');
            $this->call('migrate');
        }

        // Step 4: Seed initial data
        $this->info('Step 4: Seeding initial data...');
        $this->call('db:seed');

        $this->info('✓ Setup complete!');
        $this->line('');
        $this->info('You can now:');
        $this->line('  1. Start the Laravel server: php artisan serve');
        $this->line('  2. Start the queue worker:  php artisan queue:listen --queue=default,git-remote,ediarum-services,exist-db');
        $this->line('  3. Compile frontend assets: npm run dev');
        $this->line('');
        $this->line('Access the application at: http://localhost:8000');

        return Command::SUCCESS;
    }
}
