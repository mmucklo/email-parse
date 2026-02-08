<?php
$expected_invalid = false;
$actual_invalid = '';

$expected_reason = null;
$actual_reason = '';

echo "invalid comparison:\n";
echo "  Expected: " . var_export($expected_invalid, true) . " (" . gettype($expected_invalid) . ")\n";
echo "  Actual: " . var_export($actual_invalid, true) . " (" . gettype($actual_invalid) . ")\n";
echo "  Equal (==): " . ($expected_invalid == $actual_invalid ? 'true' : 'false') . "\n";
echo "  Identical (===): " . ($expected_invalid === $actual_invalid ? 'true' : 'false') . "\n\n";

echo "invalid_reason comparison:\n";
echo "  Expected: " . var_export($expected_reason, true) . " (" . gettype($expected_reason) . ")\n";
echo "  Actual: " . var_export($actual_reason, true) . " (" . gettype($actual_reason) . ")\n";
echo "  Equal (==): " . ($expected_reason == $actual_reason ? 'true' : 'false') . "\n";
echo "  Identical (===): " . ($expected_reason === $actual_reason ? 'true' : 'false') . "\n";
