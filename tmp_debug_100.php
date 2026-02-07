<?php
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/Parse.php';

use Email\Parse;
use Email\ParseOptions;
use Email\LengthLimits;

$limits = new LengthLimits(64, 254, 8);
$options = new ParseOptions([], [','], true, $limits);
$parser = new Parse(null, $options);

$result = $parser->parse('test@aaaaaaaaa.example.com', false);

echo "Invalid: " . ($result['invalid'] ? 'true' : 'false') . "\n";
echo "Reason: " . ($result['invalid_reason'] ?? 'null') . "\n";
echo "Domain: " . $result['domain'] . "\n";
