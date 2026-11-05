<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectLockedPath;
use App\Sabre\SabreResponseTranslator;
use App\Sabre\Sapi;
use App\Sabre\WebDavBaseUri;
use App\Sabre\WebDavEventBridge;
use App\Services\LockTableManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Sabre\DAV;
use Sabre\HTTP\Request as SabreRequest;

/*
 *
 * Adapted from: https://github.com/monicahq/laravel-sabre/blob/main/src/Sabre/Server.php
 *
 * */

class WebDavController extends Controller
{
    //
    public function __invoke(Request $request)
    {

        $project = Project::where('slug', $request->projectSlug)->firstOrFail();

        if (!Gate::allows('edit-project-files', $project->id)) {
            abort(403);
        }

        if($project->is_in_maintenance_mode){
            abort(503, "The project {$project->name} is currently locked due to a data transformation in progress.
            Please try again later.");
        }

        if (!$project->initialized_at) {
            abort(503, "Project '{$project->name}' has not been initialized yet.");
        }

        // Sabre\DAV\FS\Directory doesn't check this itself -- it happily wraps a path that doesn't
        // exist, and only blows up later (an uncaught ErrorException from a failed filemtime()/stat)
        // the moment it actually needs to stat the directory, which crashes as an unhandled 500. A
        // missing data folder is a server-side state problem (mid-migration, not yet initialized,
        // deleted), not a bad request URL, so this is treated the same as the maintenance-mode guard
        // above -- 503, not 404.
        if (!File::isDirectory($project->data_folder_location)) {
            // The server path goes to the log only; WebDAV clients never see it.
            Log::error("The data folder for project '{$project->name}' could not be found at "
                . "'{$project->data_folder_location}'.");
            abort(503, "The data folder for project '{$project->name}' could not be found on the server. "
                . "This usually means the project's repository has not been initialized yet, or its data "
                . "folder was moved or deleted. Please contact an administrator.");
        }

        $rootDirectory = new DAV\FS\Directory($project->data_folder_location);
        $server = new DAV\Server($rootDirectory);
        $server->debugExceptions = config('app.debug');

        $sapi = new Sapi();
        $server->sapi = $sapi;

        // Sabre\DAV\Server's constructor builds its request from Sabre\HTTP\Sapi::getRequest(),
        // which reads the raw $_SERVER superglobal and php://input directly -- it never sees the
        // Laravel $request this controller received. Overwrite it explicitly so sabre operates on
        // the same request Laravel's middleware pipeline produced (this is also what makes the
        // route testable via Laravel's HTTP test helpers, which never touch $_SERVER/php://input).
        $subpath = config('app.subpath');
        $server->httpRequest = self::buildSabreRequest($request, $subpath);

        $server->setBaseUri(WebDavBaseUri::build($subpath, $request->projectSlug));
        $server->setLogger(Log::getLogger());

        //Note sure why errors are not getting logged...
        if (!Storage::disk('local')->exists('webdav-locks')) {
            Storage::disk('local')->makeDirectory('webdav-locks');
        }
        $pdo = DB::getPdo();
        $lockBackend = new DAV\Locks\Backend\PDO($pdo);
        $lockBackend->tableName = LockTableManager::tableNameFor($project->id);
        $lockPlugin = new DAV\Locks\Plugin($lockBackend);

        $server->addPlugin($lockPlugin);

        $server->addPlugin(new DAV\Browser\Plugin());

        if($request->method() === "LOCK"){
            Log::debug("Lock Request If Header: " . $request->header('If'));
            Log::debug("Lock Request Body: " . $request->getContent());
        }
        if($request->method() === "PUT"){
            Log::debug("PUT Request If Header: " . $request->header('If'));
        }



        $server->on('beforeLock', function ($path, \Sabre\DAV\Locks\LockInfo $lock) use ($request) {
            $lock->owner = $request->user()->email;
        });
        $server->on('beforeUnLock', function ($path, \Sabre\DAV\Locks\LockInfo $lock) use ($request) {
            $lock->owner = $request->user()->email;
        });

        // Admin-imposed path locks (ProjectLockedPath), distinct from the WebDAV editing locks
        // above: refuse any write under a locked path, and refuse LOCK itself so a client that locks
        // on open (e.g. oXygen) never gets an editable buffer for a locked file in the first place.
        // See planning/lock-directories.md Part C for why Locked is thrown rather than the listener
        // returning false, and why beforeMethod:LOCK needs $server->getRequestUri() explicitly.
        $guardAgainstLockedPath = function (string $path) use ($project) {
            if (ProjectLockedPath::coveringPath($project->id, $path)) {
                throw new DAV\Exception\Locked();
            }
        };
        $server->on('beforeBind', $guardAgainstLockedPath);
        $server->on('beforeWriteContent', $guardAgainstLockedPath);
        $server->on('beforeUnbind', $guardAgainstLockedPath);
        $server->on('beforeMethod:LOCK', function () use ($guardAgainstLockedPath, $server) {
            $guardAgainstLockedPath($server->getRequestUri());
        });

        $events = ['afterCreateFile', 'afterWriteContent', 'afterUnbind', 'afterMove',];
        foreach ($events as $event) {
            $server->on($event, function ($path, $destinationPath = null) use ($event, $project, $request) {
                WebDavEventBridge::forward($event, $project, $request->user()->email, $path, $destinationPath);
            });
        }
        $server->start();

        return SabreResponseTranslator::toLaravelResponse($server->httpResponse);
    }

    /**
     * Builds sabre's request URL from Laravel's own reliably-resolved getPathInfo() (the logical
     * path after Symfony strips SCRIPT_NAME/any front-controller subdirectory), not the raw
     * getRequestUri(). getRequestUri() reflects whatever the webserver actually handed PHP, which
     * for a real subpath deployment (nginx serving this app from e.g. /webdav via a docroot mapping)
     * includes that subpath -- while Laravel's own router, and this test suite's HTTP test harness,
     * both already resolve to the subpath-free path. Prefixing that resolved path with $subpath
     * ourselves keeps this request URL and WebDavBaseUri::build()'s base URI (below) always
     * consistent with each other, in every deployment shape, rather than depending on exactly how
     * the webserver in front of PHP happens to construct REQUEST_URI. Without this, sabre's own
     * path-vs-base-uri check (Sabre\HTTP\Request::getPath()) throws a LogicException the moment
     * $subpath is non-empty and doesn't happen to already be baked into getRequestUri().
     */
    private static function buildSabreRequest(Request $request, ?string $subpath): SabreRequest
    {
        $path = rtrim((string) $subpath, '/') . $request->getPathInfo();

        if ($queryString = $request->getQueryString()) {
            $path .= '?' . $queryString;
        }

        $sabreRequest = new SabreRequest($request->getMethod(), $path);
        $sabreRequest->addHeaders($request->headers->all());
        $sabreRequest->setBody($request->getContent());

        return $sabreRequest;
    }
}
