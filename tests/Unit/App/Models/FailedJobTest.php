<?php

namespace Tests\Unit\App\Models;

use App\Models\FailedJob;
use PHPUnit\Framework\TestCase;

class FailedJobTest extends TestCase
{
    /**
     * `command()`'s decoding of a real payload (including the DB round-trip Laravel's
     * SerializesModels does on the embedded `project`) is covered in
     * Tests\Feature\Project\FailedPushRetryServiceTest -- it needs a persisted Project to
     * unserialize against, which this plain, DB-less unit test can't provide.
     */
    public function test_uuid_reads_the_uuid_out_of_the_payload(): void
    {
        $job = new FailedJob();
        $job->payload = json_encode(['uuid' => 'a-uuid', 'data' => ['command' => '']]);

        $this->assertSame('a-uuid', $job->uuid());
    }

    /**
     * `exception` holds the literal output of PHP's `(string) $e` -- this is exactly what
     * Exception::__toString() produces for a job thrown from a real absolute file path, confirmed by
     * actually triggering one (`php -r 'throw new Exception(...)'`) rather than guessed.
     */
    public function test_reason_strips_the_exception_class_prefix_and_file_line_suffix(): void
    {
        $job = new FailedJob();
        $job->exception = "Exception: Push to Git remote failed: remote: HTTP Basic: Access denied in /var/www/webdav/app/Jobs/PushToGitRemote.php:50\nStack trace:\n#0 {main}";

        $this->assertSame('Push to Git remote failed: remote: HTTP Basic: Access denied', $job->reason());
    }

    public function test_reason_falls_back_to_the_raw_first_line_when_the_format_does_not_match(): void
    {
        $job = new FailedJob();
        $job->exception = "Some custom __toString() output with no recognizable shape\nmore detail on another line";

        $this->assertSame('Some custom __toString() output with no recognizable shape', $job->reason());
    }

    public function test_reason_does_not_strip_a_colon_that_is_part_of_the_message_itself(): void
    {
        $job = new FailedJob();
        $job->exception = "RuntimeException: 'other' has diverged from 'main': rebase required in /a/b/c.php:12\nStack trace:\n#0 {main}";

        $this->assertSame("'other' has diverged from 'main': rebase required", $job->reason());
    }
}
