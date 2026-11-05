<?php

namespace App\Jobs;

use App\Exceptions\HttpResponse;
use App\Exceptions\UnknownWebDavHook;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reports a single WebDAV change to the ediarum services as one `POST {url}/files` (multipart):
 * `event` (created|modified|moved|deleted), `project` (slug), `commit` (the webdav commit holding
 * the change, when there is one), `path`, `previous_path` (moved only) and `file` (the raw bytes,
 * everything but deleted). URL and key come from the .env and are the same for every project.
 *
 * The job gets paths relative to the WebDAV root, the repository's Project::DATA_FOLDER, but sends
 * them relative to the repository root (`data/letters/foo.xml`), as they appear in the repository.
 */
class PushToEdiarumServices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Project $project,
        public string  $webDavEvent,
        public string  $sourcePath,
        public ?string $destinationPath = null,
        public ?string $commit = null,
    )
    {
    }

    /**
     * Pushing needs the global services URL and key, and the project's own switch turned on.
     */
    public static function isEnabledFor(Project $project): bool
    {
        return $project->push_to_ediarum_services && self::url() && self::apiKey();
    }

    public static function url(): ?string
    {
        return config('services.ediarum_services.url') ? rtrim(config('services.ediarum_services.url'), '/') : null;
    }

    public static function apiKey(): ?string
    {
        return config('services.ediarum_services.api_key') ?: null;
    }

    private function handleRes(Response $res): void
    {
        if ($res->failed()) {
            $status = $res->status();
            $body = $res->body();
            throw new HttpResponse("Request to ediarum services failed with status $status and response $body");
        }

        Log::debug("Request to " . $res->effectiveUri() . " successful: " . $res->body());
    }

    private function post(string $event, string $path, ?string $previousPath = null): void
    {
        $fields = [
            'event' => $event,
            'project' => $this->project->slug,
            'commit' => $this->commit,
            'path' => Project::toRepositoryPath($path),
            'previous_path' => $previousPath === null ? null : Project::toRepositoryPath($previousPath),
        ];

        $request = Http::withToken(self::apiKey())->asMultipart();
        foreach (array_filter($fields, fn ($value) => $value !== null) as $name => $value) {
            $request = $request->attach($name, $value);
        }
        if ($event !== 'deleted') {
            $request = $request->attach('file', File::get($this->absolutePath($path)), basename($path));
        }

        $this->handleRes($request->post(self::url() . '/files'));
    }

    private function absolutePath(string $path): string
    {
        return $this->project->data_folder_location . '/' . ltrim($path, '/');
    }

    /**
     * A moved directory is reported file by file, each with the previous path it had inside the
     * source directory -- the services only ever deal in files.
     */
    private function move(string $source, string $destination): void
    {
        $absoluteDestination = $this->absolutePath($destination);

        if (File::isFile($absoluteDestination)) {
            $this->post('moved', $destination, $source);

            return;
        }

        foreach (File::allFiles($absoluteDestination) as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
            $this->post('moved', rtrim($destination, '/') . '/' . $relative, rtrim($source, '/') . '/' . $relative);
        }
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        match ($this->webDavEvent) {
            'afterCreateFile' => $this->post('created', $this->sourcePath),
            'afterWriteContent' => $this->post('modified', $this->sourcePath),
            'afterMove' => $this->move($this->sourcePath, $this->destinationPath),
            'afterUnbind' => $this->post('deleted', $this->sourcePath),
            default => throw new UnknownWebDavHook($this->webDavEvent),
        };
    }
}
