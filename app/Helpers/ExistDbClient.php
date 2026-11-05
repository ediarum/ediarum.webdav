<?php

namespace App\Helpers;

use DOMDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Exceptions\ExistDBResourceNotFound;

class ExistDbClient
{
    private PendingRequest $httpClient;

    public function __construct(string $existBaseUrl, string $existDbUser, ?string $existDbPassword)
    {
        $this->httpClient = Http::withBasicAuth($existDbUser, $existDbPassword ?? '')
            ->baseUrl(rtrim($existBaseUrl, " /"));
    }


    /**
     * @throws ConnectionException
     */
    private function getUrl($url)
    {
        Log::debug("Getting $url.");
        $response = $this->httpClient->get($url);
        if ($response->notFound()) {
            throw new ExistDBResourceNotFound("Resource not found at " . $response->effectiveUri());
        }
        if ($response->failed()) {
            throw new \Exception(ExistDbResponseError::describe($response));
        }
        return $response;
    }

    public function getDirectory($path): FolderContents
    {
        $trimmed = trim($path, ' /');
        $existDBFolder = new FolderContents();
        try {
            $response = $this->getUrl("rest/$trimmed");
            $existDBFolder->parseExistDBCollectionContents($response->body());
        } catch (ExistDBResourceNotFound $e) {
            Log::debug("Resource not found at $path");
        }
        return $existDBFolder;

    }

    public function deleteResource($path)
    {
        $trimmed = trim($path, ' /');
        $response = $this->httpClient->delete("rest/$trimmed");
        if ($response->failed()) {
            if ($response->status() == 404) {
                throw new ExistDBResourceNotFound("Resource not found at " . $response->effectiveUri());
            } else {
                throw new \Exception(ExistDbResponseError::describe($response));
            }
        }
    }

    public function createResource($existPath, $filePath)
    {
        $trimmed = trim($existPath, ' /');
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $contentType = WebDavContentTypeMap::forExtension($extension) ?? 'application/octet-stream';

        $fileSize = filesize($filePath);
        $isSmall = $fileSize < 100 * 1024; // 100 KB

        if ($isSmall) {
            $content = File::get($filePath);
            Log::debug("Here is the file path $filePath");
            Log::debug("Here is the contentType $contentType");
            Log::debug("Folder contents: $content.");
            $body = $content;
        } else {
            $body = \GuzzleHttp\Psr7\Utils::streamFor(fopen($filePath, 'r'));
        }

        // Do not add ->withOptions(['debug' => true]) here -- see the equivalent comment in
        // PushToExistDb::handle(). This client is used synchronously from the manual "Push to
        // eXist-db" HTTP-streamed endpoint, so a Guzzle debug trace (Basic Auth header included)
        // would write straight into the response sent to the browser.
        $response = $this->httpClient
            ->withBody($body, $contentType)
            ->put("rest/$trimmed");

        if ($response->failed()) {
            throw new \Exception(ExistDbResponseError::describe($response));
        }
    }


}
