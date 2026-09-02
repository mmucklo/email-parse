<?php

declare(strict_types=1);

namespace Email\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rewrites the removed `Email\ParseOptions` mutating setters to their immutable
 * `withX()` builders, re-assigning the result back to the receiver.
 *
 * The old setters mutated in place and returned void; the withX() builders return
 * a new instance, so `$o->setBannedChars($x)` becomes `$o = $o->withBannedChars($x)`.
 * Only applies when the receiver is directly assignable (a variable or property);
 * anything more complex is left for manual migration.
 *
 * The setMax*Length() setters are intentionally excluded — their replacement builds
 * a new LengthLimits from the other two limits, which needs human context.
 */
final class ParseOptionsSetterToWithRector extends AbstractRector
{
    /** @var array<string, string> mutating setter => immutable builder */
    private const SETTER_TO_WITH = [
        'setBannedChars' => 'withBannedChars',
        'setSeparators' => 'withSeparators',
        'setUseWhitespaceAsSeparator' => 'withUseWhitespaceAsSeparator',
        'setLengthLimits' => 'withLengthLimits',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace a removed ParseOptions setter with its withX() builder, re-assigning the result',
            [
                new CodeSample(
                    '$options->setBannedChars($chars);',
                    '$options = $options->withBannedChars($chars);',
                ),
            ],
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [MethodCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        /** @var MethodCall $node */
        $method = $this->getName($node->name);
        if ($method === null || !isset(self::SETTER_TO_WITH[$method])) {
            return null;
        }

        // Only rewrite when the result can be assigned straight back to the receiver.
        if (!$node->var instanceof Variable && !$node->var instanceof PropertyFetch) {
            return null;
        }

        if (!$this->isObjectType($node->var, new ObjectType('Email\ParseOptions'))) {
            return null;
        }

        $with = new MethodCall($node->var, self::SETTER_TO_WITH[$method], $node->getArgs());

        return new Assign($node->var, $with);
    }
}
