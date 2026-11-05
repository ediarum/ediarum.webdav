<?php

namespace Tests\Unit\App\Helpers;

use App\Helpers\WebDavContentTypeMap;
use PHPUnit\Framework\TestCase;

class WebDavContentTypeMapTest extends TestCase
{
    public function test_known_extensions(): void
    {
        $this->assertSame('application/xml', WebDavContentTypeMap::forExtension('xml'));
        $this->assertSame('image/jpeg', WebDavContentTypeMap::forExtension('jpg'));
        $this->assertSame('image/jpeg', WebDavContentTypeMap::forExtension('jpeg'));
    }

    public function test_is_case_insensitive(): void
    {
        $this->assertSame('application/xml', WebDavContentTypeMap::forExtension('XML'));
    }

    public function test_unknown_extension_returns_null(): void
    {
        $this->assertNull(WebDavContentTypeMap::forExtension('pdf'));
    }
}
