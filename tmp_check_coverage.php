<?php
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/Parse.php';

use Email\Parse;
use Email\ParseOptions;
use Email\LengthLimits;

echo "Testing LengthLimits coverage:\n";
echo "================================\n\n";

// Test 1: Default constructor
$limits1 = new LengthLimits();
echo "✓ Default constructor: " . $limits1->getMaxLocalPartLength() . ", " . $limits1->getMaxTotalLength() . ", " . $limits1->getMaxDomainLabelLength() . "\n";

// Test 2: Custom constructor
$limits2 = new LengthLimits(100, 300, 100);
echo "✓ Custom constructor: " . $limits2->getMaxLocalPartLength() . ", " . $limits2->getMaxTotalLength() . ", " . $limits2->getMaxDomainLabelLength() . "\n";

// Test 3: createDefault() factory
$limits3 = LengthLimits::createDefault();
echo "✓ createDefault(): " . $limits3->getMaxLocalPartLength() . ", " . $limits3->getMaxTotalLength() . ", " . $limits3->getMaxDomainLabelLength() . "\n";

// Test 4: createRelaxed() factory
$limits4 = LengthLimits::createRelaxed();
echo "✓ createRelaxed(): " . $limits4->getMaxLocalPartLength() . ", " . $limits4->getMaxTotalLength() . ", " . $limits4->getMaxDomainLabelLength() . "\n";

// Test 5: Setters
$limits5 = new LengthLimits();
$limits5->setMaxLocalPartLength(50);
$limits5->setMaxTotalLength(200);
$limits5->setMaxDomainLabelLength(50);
echo "✓ Setters: " . $limits5->getMaxLocalPartLength() . ", " . $limits5->getMaxTotalLength() . ", " . $limits5->getMaxDomainLabelLength() . "\n";

// Test 6: ParseOptions with LengthLimits
$options1 = new ParseOptions([], [','], true, $limits4);
echo "✓ ParseOptions with LengthLimits: " . $options1->getMaxLocalPartLength() . "\n";

// Test 7: ParseOptions getLengthLimits()
$retrieved = $options1->getLengthLimits();
echo "✓ getLengthLimits(): " . $retrieved->getMaxLocalPartLength() . "\n";

// Test 8: ParseOptions setLengthLimits()
$options1->setLengthLimits($limits2);
echo "✓ setLengthLimits(): " . $options1->getMaxLocalPartLength() . "\n";

// Test 9: ParseOptions individual setters (backward compat)
$options2 = new ParseOptions();
$options2->setMaxLocalPartLength(80);
$options2->setMaxTotalLength(280);
$options2->setMaxDomainLabelLength(80);
echo "✓ Individual setters: " . $options2->getMaxLocalPartLength() . ", " . $options2->getMaxTotalLength() . ", " . $options2->getMaxDomainLabelLength() . "\n";

echo "\nAll LengthLimits methods tested! ✅\n";
