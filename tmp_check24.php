<?php
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/Parse.php';

use Email\Parse;
use Email\ParseOptions;

$tests = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(__DIR__.'/tests/testspec.yml'));
$test = $tests[23];

$options = new ParseOptions(['%', '!'], [',', ';']);
$parser = new Parse(null, $options);

$actual = $parser->parse($test['emails'], $test['multiple']);

echo "Email has comment: 't(comment with spaces !!!)name@[10.0.10.45]'\n\n";

echo "First email expected comments:\n";
var_dump($test['result']['email_addresses'][0]['comments']);

echo "\nFirst email actual comments:\n";
var_dump($actual['email_addresses'][0]['comments']);
