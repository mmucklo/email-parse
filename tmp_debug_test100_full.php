<?php
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/Parse.php';

use Email\Parse;
use Email\ParseOptions;
use Email\LengthLimits;

$tests = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(__DIR__.'/tests/testspec.yml'));
$test = $tests[99]; // Test #100

$useWhitespaceAsSeparator = $test['use_whitespace_as_separator'] ?? true;
$separators = $test['separators'] ?? [',', ';'];

$lengthLimits = null;
if (isset($test['use_relaxed_limits']) && $test['use_relaxed_limits']) {
    $lengthLimits = LengthLimits::createRelaxed();
} elseif (isset($test['max_local_part_length']) || isset($test['max_total_length']) || isset($test['max_domain_label_length'])) {
    $lengthLimits = new LengthLimits(
        $test['max_local_part_length'] ?? 64,
        $test['max_total_length'] ?? 254,
        $test['max_domain_label_length'] ?? 63
    );
}

$options = new ParseOptions(['%', '!'], $separators, $useWhitespaceAsSeparator, $lengthLimits);
$parser = new Parse(null, $options);

$actual = $parser->parse($test['emails'], $test['multiple']);
$expected = $test['result'];

echo "Expected:\n";
print_r($expected);

echo "\nActual:\n";
print_r($actual);

echo "\nEqual: " . ($expected === $actual ? 'YES' : 'NO') . "\n";
