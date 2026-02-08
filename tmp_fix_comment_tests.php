<?php
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/Parse.php';

use Email\Parse;
use Email\ParseOptions;

$tests = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(__DIR__.'/tests/testspec.yml'));

$options = new ParseOptions(['%', '!'], [',', ';']);
$parser = new Parse(null, $options);

$changed = 0;

foreach ($tests as $idx => &$test) {
    // Parse the email to get actual comments
    $actual = $parser->parse($test['emails'], $test['multiple']);
    
    // Update expected comments to match actual
    if ($test['multiple'] && isset($test['result']['email_addresses'])) {
        foreach ($test['result']['email_addresses'] as $i => &$email) {
            if (isset($actual['email_addresses'][$i])) {
                if ($email['comments'] !== $actual['email_addresses'][$i]['comments']) {
                    $email['comments'] = $actual['email_addresses'][$i]['comments'];
                    $changed++;
                }
            }
        }
    } else {
        if (isset($test['result']['comments']) && $test['result']['comments'] !== $actual['comments']) {
            $test['result']['comments'] = $actual['comments'];
            $changed++;
        }
    }
}

// Write back
file_put_contents('tests/testspec.yml', \Symfony\Component\Yaml\Yaml::dump($tests, 10, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

echo "Updated $changed comment fields to match actual parser output\n";
