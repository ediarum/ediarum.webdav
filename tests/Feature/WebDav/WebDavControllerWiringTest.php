<?php

namespace Tests\Feature\WebDav;

class WebDavControllerWiringTest extends WebDavTestCase
{
    /**
     * With no APP_SUBPATH configured, PROPFIND hrefs must be rooted exactly at /connection/{slug}/...
     * -- this is what every WebDAV client navigates the whole tree by, so a regression here silently
     * breaks folder browsing for everyone.
     */
    public function test_propfind_hrefs_are_rooted_at_the_connection_path_with_no_subpath(): void
    {
        config(['app.subpath' => '']);
        file_put_contents($this->fullPath('foo.xml'), '<root/>');

        $response = $this->webdav('PROPFIND', '/', ['Depth' => '1']);

        $response->assertStatus(207);
        $this->assertStringContainsString(
            "<d:href>/connection/{$this->project->slug}/foo.xml</d:href>",
            $response->getContent()
        );
    }

    /**
     * This app's actual production deployment serves it from a subpath (e.g. /webdav, via nginx
     * mapping that path to this app's docroot) -- APP_SUBPATH exists specifically for that. Requests
     * Laravel actually sees (in production, and in this HTTP test harness) never include that
     * subpath in their resolved path -- only WebDavController's own request/base-uri construction
     * needs to know about it, to reconstruct the externally-visible URL. This is the regression test
     * for that: sabre's own path-vs-base-uri check (Sabre\HTTP\Request::getPath()) throws a
     * LogicException the moment the configured base URI isn't a prefix of sabre's request URL, which
     * is exactly what happened before WebDavController built that URL from Laravel's own
     * getPathInfo() instead of the raw, deployment-dependent getRequestUri().
     */
    public function test_propfind_hrefs_are_rooted_at_the_subpath_prefixed_connection_path_when_a_subpath_is_configured(): void
    {
        config(['app.subpath' => '/webdav']);
        file_put_contents($this->fullPath('foo.xml'), '<root/>');

        $response = $this->webdav('PROPFIND', '/', ['Depth' => '1']);

        $response->assertStatus(207);
        $this->assertStringContainsString(
            "<d:href>/webdav/connection/{$this->project->slug}/foo.xml</d:href>",
            $response->getContent()
        );
    }

    /**
     * Not just PROPFIND's href generation -- a subpath being configured must not break basic
     * dispatch for any other WebDAV method either, since sabre's base-uri check runs for every
     * request regardless of which one throws first.
     */
    public function test_a_basic_webdav_operation_still_works_when_a_subpath_is_configured(): void
    {
        config(['app.subpath' => '/webdav']);

        $response = $this->webdav('PUT', '/foo.xml', [], '<root/>');

        $response->assertStatus(201);
        $this->assertFileExists($this->fullPath('foo.xml'));
    }

    /**
     * Sabre\DAV\Server::start() catches every \Throwable from method invocation and renders it as an
     * XML error body -- but only includes file/line/stacktrace when $server->debugExceptions is true,
     * which the controller ties directly to config('app.debug'). If that's ever left true in
     * production, every WebDAV error response leaks server file paths and stack traces to the client.
     */
    public function test_exception_details_are_not_leaked_when_debug_is_off(): void
    {
        config(['app.debug' => false]);
        // "existing" is a file, not a collection -- PUTting a child path under it forces sabre to
        // throw while resolving the tree, regardless of what specific exception type it is.
        file_put_contents($this->fullPath('existing'), 'not a directory');

        $response = $this->webdav('PUT', '/existing/child.xml', [], '<root/>');

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $body = $response->getContent();
        $this->assertStringNotContainsString('<s:file>', $body);
        $this->assertStringNotContainsString('<s:stacktrace>', $body);
        $this->assertStringNotContainsString(base_path(), $body);
    }

    public function test_exception_details_are_included_when_debug_is_on(): void
    {
        config(['app.debug' => true]);
        file_put_contents($this->fullPath('existing'), 'not a directory');

        $response = $this->webdav('PUT', '/existing/child.xml', [], '<root/>');

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $body = $response->getContent();
        $this->assertStringContainsString('<s:file>', $body);
        $this->assertStringContainsString('<s:stacktrace>', $body);
    }

    public function test_malformed_propfind_body_returns_a_dav_error_not_a_laravel_500(): void
    {
        $response = $this->webdav('PROPFIND', '/', ['Content-Type' => 'application/xml'], 'this is not xml');

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());
    }

    public function test_get_on_a_directory_with_html_accept_returns_the_browser_plugin_listing(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');

        $response = $this->webdav('GET', '/', ['Accept' => 'text/html']);

        $response->assertStatus(200);
        $this->assertStringContainsString('<html', strtolower($response->getContent()));
    }

    public function test_propfind_on_root_still_returns_xml_regardless_of_browser_plugin(): void
    {
        file_put_contents($this->fullPath('foo.xml'), '<root/>');

        $response = $this->webdav('PROPFIND', '/', ['Depth' => '1']);

        $response->assertStatus(207);
        $response->assertHeader('Content-Type', 'application/xml; charset=utf-8');
    }
}
