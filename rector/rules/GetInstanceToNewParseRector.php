<?php

declare(strict_types=1);

namespace Email\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name\FullyQualified;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rewrites the deprecated `Email\Parse::getInstance()` singleton (removed in 5.0)
 * to explicit instantiation `new Email\Parse()`.
 *
 * Safe for the common case: the singleton only shared default options, so a fresh
 * instance parses identically. If you relied on mutating the shared instance (via
 * the also-deprecated setOptions()), migrate that to constructor injection by hand.
 */
final class GetInstanceToNewParseRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace the deprecated Parse::getInstance() singleton with explicit instantiation',
            [
                new CodeSample(
                    'Parse::getInstance()->parseSingle($email);',
                    '(new Parse())->parseSingle($email);',
                ),
            ],
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [StaticCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        /** @var StaticCall $node */
        if (!$this->isName($node->class, 'Email\Parse')) {
            return null;
        }

        if (!$this->isName($node->name, 'getInstance')) {
            return null;
        }

        return new New_(new FullyQualified('Email\Parse'));
    }
}
