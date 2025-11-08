<?php

namespace Email\Tests;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../src/Parse.php';

use Email\Parse;

class ParseTest extends \PHPUnit\Framework\TestCase
{
    public function testParseEmailAddresses()
    {
        $tests = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(__DIR__.'/testspec.yml'));

        foreach ($tests as $test) {
            $emails = $test['emails'];
            $multiple = $test['multiple'];
            $result = $test['result'];

            $this->assertSame($result, Parse::getInstance()->parse($emails, $multiple));
        }
    }
}
