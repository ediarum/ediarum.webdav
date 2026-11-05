<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    /**
     * Every project repository keeps its edition files in this top-level folder, and it is the
     * WebDAV root. A fixed convention, not a setting: the ediarum services receive paths with it
     * (see toRepositoryPath()) and the platform's apps rely on the same repository layout.
     */
    public const DATA_FOLDER = 'data';

    protected $fillable = [
        'name',
        'slug',
        'git_remote_url',
        'git_username',
        'git_branch',
        'push_to_ediarum_services',
        'is_index_app_source_of_truth',
        'exist_base_url',
        'exist_data_path',
        'exist_username',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'git_access_token',
        'exist_password',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'git_access_token' => 'encrypted',
        'exist_password' => 'encrypted',
        'push_to_ediarum_services' => 'boolean',
        'is_index_app_source_of_truth' => 'boolean',
        'git_credentials_broken_at' => 'datetime',
        'git_commit_broken_at' => 'datetime',
        'initialized_at' => 'datetime',
    ];

    /**
     * The git repo root -- `{repositories_directory}/{slug}`. Derived rather than stored so nothing
     * about where a project's files live needs to be kept in sync in the database; see
     * planning/init-project.md Part A.
     */
    protected function repositoryPath(): Attribute
    {
        return Attribute::get(
            fn () => rtrim(config('app.repositories_directory'), '/') . '/' . $this->slug
        );
    }

    /**
     * The WebDAV-served directory -- kept as the name and meaning every existing call site already
     * expects (a plain property read), just computed now instead of stored.
     */
    protected function dataFolderLocation(): Attribute
    {
        return Attribute::get(fn () => $this->repository_path . '/' . self::DATA_FOLDER);
    }

    /** `letters/foo.xml` (relative to the WebDAV root) → `data/letters/foo.xml` (relative to the repository). */
    public static function toRepositoryPath(string $dataPath): string
    {
        return self::DATA_FOLDER . '/' . ltrim($dataPath, '/');
    }

    /** `data/letters/foo.xml` (relative to the repository) → `letters/foo.xml`; null for paths outside the data folder. */
    public static function toDataPath(string $repositoryPath): ?string
    {
        $prefix = self::DATA_FOLDER . '/';

        return str_starts_with($repositoryPath, $prefix) ? substr($repositoryPath, strlen($prefix)) : null;
    }

    /**
     * The commit's page on the Git host, derived from git_remote_url (https or scp-style ssh, with or
     * without .git and embedded credentials). Null if the project has no Git remote. github.com uses
     * /commit/<hash>; everything else gets GitLab's /-/commit/<hash>, since a self-hosted GitLab
     * can't be told apart by its hostname.
     */
    public function remoteCommitUrl(string $hash): ?string
    {
        $url = trim((string) $this->git_remote_url);
        if ($url === '') {
            return null;
        }

        if (preg_match('#^[^/@:]+@([^:/]+):(.+)$#', $url, $m)) {
            // git@host:group/repo.git
            $host = $m[1];
            $base = "https://{$m[1]}/{$m[2]}";
        } else {
            $parts = parse_url($url);
            if (!isset($parts['host'])) {
                return null;
            }
            // An ssh:// port is the ssh daemon's, not the web server's, so only keep http(s) ports.
            $isWeb = in_array($parts['scheme'] ?? '', ['http', 'https'], true);
            $scheme = $isWeb ? $parts['scheme'] : 'https';
            $port = $isWeb && isset($parts['port']) ? ":{$parts['port']}" : '';
            $host = $parts['host'];
            $base = "{$scheme}://{$parts['host']}{$port}" . ($parts['path'] ?? '');
        }

        $commitPath = strtolower($host) === 'github.com' ? '/commit/' : '/-/commit/';

        return preg_replace('#(\.git)?/*$#', '', $base) . $commitPath . rawurlencode($hash);
    }

    public function users():BelongsToMany{
        return $this->belongsToMany(User::class)->orderBy("name")->withTimestamps();
    }

    public function commits(): HasMany
    {
        return $this->hasMany(ProjectCommit::class);
    }

    public function lockedPaths(): HasMany
    {
        return $this->hasMany(ProjectLockedPath::class);
    }
}
