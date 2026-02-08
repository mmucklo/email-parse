<?php
require_once __DIR__.'/vendor/autoload.php';

$tests = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(__DIR__.'/tests/testspec.yml'));
$test = $tests[17]; // 0-indexed

echo "Test #18:\n";
echo "Emails: " . $test['emails'] . "\n";
echo "Multiple: " . ($test['multiple'] ? 'true' : 'false') . "\n\n";

echo "Expected result keys:\n";
print_r(array_keys($test['result']));

if (isset($test['result']['email_addresses'])) {
    echo "\nFirst email keys:\n";
    print_r(array_keys($test['result']['email_addresses'][0]));
}
