<?php
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/Parse.php';

use Email\Parse;
use Email\ParseOptions;

$tests = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(__DIR__.'/tests/testspec.yml'));
$test = $tests[17];

$options = new ParseOptions(['%', '!'], [',', ';']);
$parser = new Parse(null, $options);

$actual = $parser->parse($test['emails'], $test['multiple']);
$expected = $test['result'];

echo "Expected comments:\n";
var_dump($expected['comments']);

echo "\nActual comments:\n";
var_dump($actual['comments']);

echo "\nEqual: " . ($expected['comments'] === $actual['comments'] ? 'YES' : 'NO') . "\n";

echo "\nExpected invalid:\n";
var_dump($expected['invalid']);

echo "\nActual invalid:\n";
var_dump($actual['invalid']);
