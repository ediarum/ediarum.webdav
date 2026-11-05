<?php

namespace Database\Factories;

use App\Models\Project;
use App\Services\LockTableManager;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\File;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'slug' => fake()->unique()->slug(),
            'is_in_maintenance_mode' => false,
            'git_branch' => 'main',
            'git_remote_url' => 'https://git.example.org/group/' . fake()->unique()->slug() . '.git',
            'git_username' => 'git-user',
            'git_access_token' => 'git-token',
            'initialized_at' => now(),
        ];
    }

    /**
     * A project that hasn't (yet, or successfully) gone through ProjectInitializer -- its
     * data_folder_location has no reason to exist on disk, and the rest of the app is expected to
     * gate itself off (see planning/init-project.md Part D) rather than assume it does.
     */
    public function uninitialized(): static
    {
        return $this->state(fn () => ['initialized_at' => null]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Project $project) {
            LockTableManager::ensureTableForProject($project->id);

            if ($project->initialized_at !== null) {
                File::ensureDirectoryExists($project->data_folder_location);
            }
        });
    }
}
