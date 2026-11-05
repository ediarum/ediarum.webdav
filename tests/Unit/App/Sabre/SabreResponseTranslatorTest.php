<?php

namespace Tests\Unit\App\Sabre;

use App\Sabre\SabreResponseTranslator;
use Sabre\HTTP\Response as SabreResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class SabreResponseTranslatorTest extends TestCase
{
    public function test_string_body_becomes_a_plain_response(): void
    {
        $sabreResponse = new SabreResponse(200, ['Content-Type' => 'application/xml'], '<d:multistatus/>');

        $response = SabreResponseTranslator::toLaravelResponse($sabreResponse);

        $this->assertNotInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<d:multistatus/>', $response->getContent());
        $this->assertSame('application/xml', $response->headers->get('Content-Type'));
    }

    public function test_null_body_becomes_a_plain_response(): void
    {
        $sabreResponse = new SabreResponse(204, [], null);

        $response = SabreResponseTranslator::toLaravelResponse($sabreResponse);

        $this->assertNotInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_stream_body_with_content_length_streams_exactly_that_many_bytes(): void
    {
        $content = 'hello world, this has extra bytes after the declared length';
        $declaredLength = 11; // "hello world"
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $sabreResponse = new SabreResponse(200, ['Content-Length' => (string) $declaredLength], $stream);

        $response = SabreResponseTranslator::toLaravelResponse($sabreResponse);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        $response->sendContent();
        $streamed = ob_get_clean();
        $this->assertSame('hello world', $streamed);
    }

    public function test_stream_body_without_content_length_streams_the_whole_body(): void
    {
        $content = 'the entire body';
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $sabreResponse = new SabreResponse(200, [], $stream);

        $response = SabreResponseTranslator::toLaravelResponse($sabreResponse);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        $response->sendContent();
        $streamed = ob_get_clean();
        $this->assertSame($content, $streamed);
    }
}
