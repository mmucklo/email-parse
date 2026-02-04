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
            
            // Configure Parse to support both comma and semicolon as separators
            $options = new ParseOptions(['%', '!'], [',', ';'], $useWhitespaceAsSeparator);
            $parser = new Parse(null, $options);

            $this->assertSame($result, $parser->parse($emails, $multiple));
        }
    }
}
