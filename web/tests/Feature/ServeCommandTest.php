<?php

namespace Tests\Feature;

use Illuminate\Foundation\Console\ServeCommand;
use Tests\TestCase;

class ServeCommandTest extends TestCase
{
    public function test_dev_server_receives_temp_directory_for_uploads(): void
    {
        // без TEMP/TMP PHP под Windows пишет загрузки в C:\Windows\Temp и падает «unable to create a temporary file»
        $this->assertContains('TEMP', ServeCommand::$passthroughVariables);
        $this->assertContains('TMP', ServeCommand::$passthroughVariables);
        $this->assertContains('TMPDIR', ServeCommand::$passthroughVariables);
    }
}
