<?php
require_once __DIR__.'/vendor/autoload.php';

$tests = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(__DIR__.'/tests/testspec.yml'));
echo "Total tests: " . count($tests) . "\n";

if (count($tests) >= 100) {
    $test = $tests[99]; // 0-indexed
    echo "Test #100:\n";
    echo "  emails: " . $test['emails'] . "\n";
    echo "  Has max_domain_label_length: " . (isset($test['max_domain_label_length']) ? $test['max_domain_label_length'] : 'no') . "\n";
}
