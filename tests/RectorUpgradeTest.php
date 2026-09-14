<?php

namespace Email\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Runs the shipped 3.x -> 4.0 Rector config against a fixture that exercises
 * every ownership branch of the setter rule (owned variable, withX() chain,
 * escape by argument / copy, ->getOptions() alias, parameter, own property),
 * the nullsafe getter rewrite, and getInstance(). The expected output is the
 * contract users get from `rector process --config rector/upgrade-4.0.php`.
 */
final class RectorUpgradeTest extends TestCase
{
    public function testUpgradeConfigRewritesFixtureAsDocumented(): void
    {
        $root = \dirname(__DIR__);
        $rector = $root.'/bin/rector';
        if (!is_executable($rector)) {
            $this->markTestSkipped('rector/rector is not installed (dev dependency)');
        }

        $work = sys_get_temp_dir().'/email-parse-rector-'.bin2hex(random_bytes(4));
        mkdir($work);
        $target = $work.'/upgrade.php';
        copy(__DIR__.'/fixtures/rector/upgrade-4.0.input.php', $target);

        try {
            $cmd = sprintf(
                '%s process %s --config %s --clear-cache --no-progress-bar --no-ansi 2>&1',
                escapeshellarg($rector),
                escapeshellarg($target),
                escapeshellarg($root.'/rector/upgrade-4.0.php'),
            );
            exec($cmd, $output, $exit);
            $this->assertSame(0, $exit, "rector failed:\n".implode("\n", $output));

            $this->assertStringEqualsFile(
                __DIR__.'/fixtures/rector/upgrade-4.0.expected.php',
                (string) file_get_contents($target),
            );

            // Idempotent: a second run must not re-annotate or re-rewrite.
            exec($cmd, $output2, $exit2);
            $this->assertSame(0, $exit2);
            $this->assertStringEqualsFile(
                __DIR__.'/fixtures/rector/upgrade-4.0.expected.php',
                (string) file_get_contents($target),
            );
        } finally {
            @unlink($target);
            @rmdir($work);
        }
    }
}
