<?php
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/Parse.php';

use Email\Parse;
use Email\ParseOptions;
use Email\LengthLimits;

$tests = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(__DIR__.'/tests/testspec.yml'));

foreach ($tests as $idx => $test) {
    $testNum = $idx + 1;
    
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
    
    if ($expected !== $actual) {
        echo "Test #$testNum FAILED\n";
        echo "Input: '" . $test['emails'] . "'\n";
        
        if (is_array($expected)) {
            foreach ($expected as $key => $val) {
                if (!isset($actual[$key])) {
                    echo "Missing key in actual: $key\n";
                    break;
                } elseif ($val !== $actual[$key]) {
                    echo "Difference in '$key':\n";
                    echo "  Expected: " . var_export($val, true) . "\n";
                    echo "  Actual: " . var_export($actual[$key], true) . "\n";
                    break;
                }
            }
        }
        break;
    }
}
