<?php
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/Parse.php';

use Email\Parse;

$parser = Parse::getInstance();

// Test emails with comments from the issue
$tests = [
    'john.smith(comment)@example.com',
    '(comment)john.smith@example.com', 
    'John Doe (work) <john@example.com>',
    'john@example.com (home address)',
    'test(comment1)(comment2)@example.com',
    'test@example.com (comment with (nested) parens)'
];

foreach ($tests as $email) {
    echo "Testing: $email\n";
    $result = $parser->parse($email, false);
    echo "  Address: " . $result['address'] . "\n";
    echo "  Comments: " . json_encode($result['comments']) . "\n";
    echo "  Invalid: " . ($result['invalid'] ? 'true' : 'false') . "\n";
    echo "\n";
}
