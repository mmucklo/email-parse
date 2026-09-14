<?php

declare(strict_types=1);

namespace Email\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rewrites the deprecated `Email\ParseOptions` pass-through getters (removed in
 * 5.0) to direct reads of the corresponding `public readonly` property.
 *
 * Nullsafe calls (`$o?->getX()`) become nullsafe property reads.
 *
 * The getMax*Length() helpers are intentionally excluded — they read into
 * $lengthLimits and are not deprecated.
 */
final class ParseOptionsGetterToPropertyRector extends AbstractRector
{
    /** @var array<string, string> getter method => readonly property */
    private const GETTER_TO_PROPERTY = [
        'getBannedChars' => 'bannedChars',
        'getSeparators' => 'separators',
        'getUseWhitespaceAsSeparator' => 'useWhitespaceAsSeparator',
        'getLengthLimits' => 'lengthLimits',
        'getAllowedWhitespace' => 'allowedWhitespace',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Read the ParseOptions public readonly property instead of its deprecated getter',
            [
                new CodeSample(
                    '$options->getBannedChars();',
                    '$options->bannedChars;',
                ),
            ],
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [MethodCall::class, NullsafeMethodCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        /** @var MethodCall|NullsafeMethodCall $node */
        if ($node->isFirstClassCallable() || $node->getArgs() !== []) {
            return null;
        }

        $method = $this->getName($node->name);
        if ($method === null || !isset(self::GETTER_TO_PROPERTY[$method])) {
            return null;
        }

        if (!$this->isObjectType($node->var, new ObjectType('Email\ParseOptions'))) {
            return null;
        }

        $property = self::GETTER_TO_PROPERTY[$method];

        return $node instanceof NullsafeMethodCall
            ? new NullsafePropertyFetch($node->var, $property)
            : new PropertyFetch($node->var, $property);
    }
}
