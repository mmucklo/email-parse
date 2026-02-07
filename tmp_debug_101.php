<?php
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/Parse.php';

use Email\Parse;
use Email\ParseOptions;
use Email\LengthLimits;

$email = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.com';

echo "Email length: " . strlen($email) . "\n";
echo "Local part: 64 chars\n";
echo "Domain part: " . (strlen($email) - 65) . " chars\n\n";

$limits = new LengthLimits(64, 300, 63);
$options = new ParseOptions([], [','], true, $limits);
$parser = new Parse(null, $options);

$result = $parser->parse($email, false);

echo "Invalid: " . ($result['invalid'] ? 'true' : 'false') . "\n";
echo "Reason: " . $result['invalid_reason'] . "\n";
