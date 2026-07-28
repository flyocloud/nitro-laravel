<?php

namespace Flyo\Laravel\Tests;

use Flyo\Laravel\Components\Head;
use Flyo\Laravel\LiveEdit;

/**
 * The boot script is a javascript blob inside a php string, therefore it can not be checked by any
 * php tooling. This runs it through the node syntax checker instead.
 */
class BootScriptTest extends TestCase
{
    public function test_the_boot_script_is_valid_javascript(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is not available.');
        }

        LiveEdit::register();

        $file = tempnam(sys_get_temp_dir(), 'flyo-boot-').'.js';
        file_put_contents($file, Head::$scripts[0]);

        exec('node --check '.escapeshellarg($file).' 2>&1', $output, $exitCode);
        unlink($file);

        $this->assertSame(0, $exitCode, implode(PHP_EOL, $output));
    }
}
