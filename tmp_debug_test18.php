<?php
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/Parse.php';

use Email\Parse;
use Email\ParseOptions;

$parser = new Parse(null, new ParseOptions(['%', '!'], [',', ';']));

$email = 'Testing D Name <tname@asdf.ghjkl.com> (comment) tn(comment1)ame@asdf.gh(comment2)jkl.com tname-test1(comment3)@asdf.ghjkl.com';
$result = $parser->parse($email, false); // multiple = false

echo "Result keys:\n";
print_r(array_keys($result));

echo "\nResult:\n";
print_r($result);
