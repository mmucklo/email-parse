<?php

namespace Email\Tests;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../src/Parse.php';

use Email\Parse;
use Email\ParseOptions;

class ParseTest extends \PHPUnit\Framework\TestCase
{
    public function testParseEmailAddresses()
    {
        $tests = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(__DIR__.'/testspec.yml'));

        foreach ($tests as $test) {
            $emails = $test['emails'];
            $multiple = $test['multiple'];
            $result = $test['result'];

            // Check if test specifies use_whitespace_as_separator option
            $useWhitespaceAsSeparator = $test['use_whitespace_as_separator'] ?? true;

            // Check if test specifies custom separators
            $separators = $test['separators'] ?? [',', ';'];

            // Configure Parse to support configured separators
            $options = new ParseOptions(['%', '!'], $separators, $useWhitespaceAsSeparator);
            $parser = new Parse(null, $options);

            $this->assertSame($result, $parser->parse($emails, $multiple));
        }
    }

    /**
     * Malformed input must not be O(n^2). A long run of structural-error
     * characters was quadratic (per-character mb_substr rescans + a per-character
     * full-input log interpolation); backported the mb_str_split pass and the
     * skip-ahead log guard. Assert a wide, non-flaky linear-time budget.
     */
    public function testMalformedInputIsLinearTime()
    {
        $parser = Parse::getInstance();
        foreach (['@', '.', '<', '"'] as $char) {
            $start = microtime(true);
            $result = $parser->parse(str_repeat($char, 200000), false);
            $elapsedMs = (microtime(true) - $start) * 1000;
            $this->assertTrue($result['invalid'], "repeat({$char}) should be invalid");
            $this->assertLessThan(2000, $elapsedMs, "repeat({$char}) took {$elapsedMs}ms — possible O(n^2) regression");
        }
    }
}
