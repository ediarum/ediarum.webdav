<?php

namespace App\Jobs;

use App\Exceptions\HttpResponse;
use App\Exceptions\UnknownWebDavHook;
use App\Helpers\ExistDbResponseError;
use App\Helpers\WebDavContentTypeMap;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushToExistDb implements ShouldQueue
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
    )
    {
    }

    private function handleRes(Response $res)
    {
        if ($res->failed()) {
            throw new HttpResponse(ExistDbResponseError::describe($res));
        }
        else {
            Log::debug("Request to " . $res->effectiveUri() . "successful: " . $res->body());
        }
    }

    public static function setMimeType($full_file_path) : string
    {
        $extension = pathinfo($full_file_path, PATHINFO_EXTENSION);

        return WebDavContentTypeMap::forExtension($extension)
            ?? File::mimeType($full_file_path);
    }

    private function putFile(PendingRequest $httpClient, $path)
    {
        Log::debug("Pushing file: $path");

        $dataFolder = $this->project->data_folder_location;
        $fullPath = realpath($dataFolder) . "/" . $path;
        $mimeType = self::setMimeType($fullPath);
        Log::debug("Pushing file to eXist-db: $path with ContentType $mimeType.");
        $content = File::get($fullPath);
        $res = $httpClient
            ->withBody($content, $mimeType)
            ->put($path);
        $this->handleRes($res);
    }

    private function deletePath(PendingRequest $httpClient, $path)
    {
        $res = $httpClient
            ->delete($path);
        $this->handleRes($res);
    }

    private function putMovedFiles(PendingRequest $httpClient, $path)
    {
        $dataFolder = realpath($this->project->data_folder_location);
        $newNode = realpath($dataFolder) . "/" . $path;

        if (File::isFile($newNode)) {
            Log::debug("Moving single file: $path");
            $this->putFile($httpClient, $path);
            return;
        }

        $files = File::allFiles($newNode);

        foreach ($files as $f) {
            Log::debug("Move file from directory move $path: $f");
            $relativePath = substr($f->getRealPath(), strlen($dataFolder));
            Log::debug("Here is the file to push: $relativePath");
            $this->putFile($httpClient, $relativePath);

        }


    }

    /**
     * Execute the job.
     */
    public
    function handle(): void
    {
        $baseUrl = rtrim($this->project->exist_base_url, "/");
        $dataPath = trim($this->project->exist_data_path, "/");
        $url = implode("/", [$baseUrl, "rest", $dataPath]);
        // Do not add ->withOptions(['debug' => true]) here (it was here before and got
        // removed): with a boolean debug value, not a stream, Guzzle writes the cURL verbose
        // trace -- including the plaintext Basic Auth header -- to STDOUT, which is undefined
        // outside a CLI SAPI, so it falls back to php://output. Since this job can now run
        // synchronously inside an HTTP request (FailedPushRetryService's foreground retry), that
        // trace lands directly in the response body sent to the browser: it corrupts the JSON
        // response and leaks eXist-db credentials to whoever triggered the retry.
        $httpClient = Http::withBasicAuth($this->project->exist_username, $this->project->exist_password ?? "")
            // Without an explicit bound, an unresponsive eXist-db (down, firewalled, network
            // black hole) hangs the request indefinitely -- for a foreground retry that means
            // the PHP process gets killed by max_execution_time before this job's own
            // try/catch ever runs, so the caller sees a bare 500 with no error detail instead
            // of the message built below.
            ->connectTimeout(10)
            ->timeout(30)
            ->baseUrl($url);

        try {
            switch ($this->webDavEvent) {
                case "afterCreateFile":
                    Log::debug("Request to create File: {$this->sourcePath}");
                case "afterWriteContent":
                    Log::debug("Request to write content: {$this->sourcePath}");
                    $this->putFile($httpClient, $this->sourcePath);
                    break;
                case "afterMove":
                    Log::debug("Detected move from {$this->sourcePath} to {$this->destinationPath}.");
                    Log::debug("Creating {$this->destinationPath}");
                    $this->putMovedFiles($httpClient, $this->destinationPath);
                    break;
                case "afterUnbind":
                    Log::debug("Request to delete: {$this->sourcePath}");
                    $this->deletePath($httpClient, $this->sourcePath);
                    break;
                default:
                    throw new UnknownWebDavHook($this->webDavEvent);
            }
        } catch (ConnectionException $e) {
            // Distinguish "eXist-db answered with an error" (handleRes() below, full status
            // + body) from "eXist-db never answered at all" (DNS/refused/timeout) -- both need
            // to reach the caller as a readable message, not just swallowed by a generic 500.
            throw new HttpResponse("eXist-db at {$url} did not respond: {$e->getMessage()}");
        }
    }
}
