<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The whole schema as of November 2026, squashed from the earlier incremental migrations.
 *
 * An existing database that already ran those migrations must not run this one: record it as
 * already run, once, before the next `migrate`:
 *
 *   INSERT INTO migrations (migration, batch) VALUES ('2026_11_05_000000_create_initial_schema', 1);
 *
 * The per-project WebDAV lock tables (locks_{project}) aren't part of this: they're created at
 * runtime when a project is set up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_admin');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->boolean('is_in_maintenance_mode')->default(false);
            $table->string('git_remote_url')->nullable();
            $table->string('git_username')->nullable();
            $table->text('git_access_token')->nullable();
            $table->timestamp('git_credentials_broken_at')->nullable();
            $table->string('git_branch');
            $table->timestamp('initialized_at')->nullable();
            $table->timestamp('git_commit_broken_at')->nullable();
            $table->text('git_commit_broken_reason')->nullable();
            $table->string('exist_base_url')->nullable();
            $table->string('exist_data_path')->nullable();
            $table->string('exist_username')->nullable();
            $table->string('exist_password')->nullable();
            $table->timestamps();
            $table->boolean('push_to_ediarum_services')->default(false);
            $table->boolean('is_index_app_source_of_truth')->default(false);
        });

        Schema::create('project_user', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('project_commits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamp('committed_at');
            $table->timestamps();
            $table->string('commit_hash', 64)->nullable();
        });

        Schema::create('project_locked_paths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Tree-relative, no leading/trailing slash -- same convention as locks_{project}.uri.
            $table->string('path');
            // Admin's email, same convention as locks_{project}.owner.
            $table->string('locked_by');
            $table->timestamps();

            $table->unique(['project_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_locked_paths');
        Schema::dropIfExists('project_commits');
        Schema::dropIfExists('project_user');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
