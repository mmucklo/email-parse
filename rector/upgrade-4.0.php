<?php

declare(strict_types=1);

/**
 * Rector migration config for mmucklo/email-parse 3.x -> 4.0.
 *
 * Auto-fixes the mechanical call-site changes. Run it against YOUR source:
 *
 *     vendor/bin/rector process src --config vendor/mmucklo/email-parse/rector/upgrade-4.0.php --dry-run
 *
 * (drop --dry-run to apply, then review the diff and commit).
 *
 * Covered:
 *   - Parse::getInstance()            -> new Parse()
 *   - ParseOptions::getX()            -> ParseOptions readonly property read
 *   - ParseOptions::setX($v)          -> $o = $o->withX($v)   (banned/separators/whitespace/lengthLimits)
 *
 * NOT covered (semantic changes — migrate by hand, see UPGRADE.md):
 *   - parse($x, true|false)           -> parseMultiple()/parseSingle() (return SHAPE changes: array -> object)
 *   - setMaxLocalPartLength() etc.    -> withLengthLimits(new LengthLimits(...)) (needs the other two limits)
 *   - Parse::setOptions()             -> constructor injection
 *   - Parse::setLogger()->chain(...)  -> split (setLogger() now returns void)
 */

use Rector\Config\RectorConfig;

require_once __DIR__ . '/rules/GetInstanceToNewParseRector.php';
require_once __DIR__ . '/rules/ParseOptionsGetterToPropertyRector.php';
require_once __DIR__ . '/rules/ParseOptionsSetterToWithRector.php';

return RectorConfig::configure()
    ->withRules([
        \Email\Rector\GetInstanceToNewParseRector::class,
        \Email\Rector\ParseOptionsGetterToPropertyRector::class,
        \Email\Rector\ParseOptionsSetterToWithRector::class,
    ]);
