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

            // Check if test specifies custom length limits
            $maxLocalPartLength = $test['max_local_part_length'] ?? null;
            $maxTotalLength = $test['max_total_length'] ?? null;
            $maxDomainLabelLength = $test['max_domain_label_length'] ?? null;

            // Configure Parse to support configured separators and length limits
            $options = new ParseOptions(
                ['%', '!'],
                $separators,
                $useWhitespaceAsSeparator,
                $maxLocalPartLength,
                $maxTotalLength,
                $maxDomainLabelLength
            );
            $parser = new Parse(null, $options);

            $this->assertSame($result, $parser->parse($emails, $multiple));
        }
    }
}
