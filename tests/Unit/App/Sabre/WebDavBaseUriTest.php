<?php

namespace Tests\Unit\App\Sabre;

use App\Sabre\WebDavBaseUri;
use PHPUnit\Framework\TestCase;

class WebDavBaseUriTest extends TestCase
{
    public function test_no_subpath(): void
    {
        $this->assertSame('/connection/my-project', WebDavBaseUri::build('', 'my-project'));
        $this->assertSame('/connection/my-project', WebDavBaseUri::build(null, 'my-project'));
    }

    public function test_subpath_without_trailing_slash(): void
    {
        $this->assertSame('/ediarum/connection/my-project', WebDavBaseUri::build('/ediarum', 'my-project'));
    }

    public function test_subpath_with_trailing_slash_does_not_double_the_slash(): void
    {
        $this->assertSame('/ediarum/connection/my-project', WebDavBaseUri::build('/ediarum/', 'my-project'));
    }
}
