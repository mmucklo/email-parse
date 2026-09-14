<?php

declare(strict_types=1);

namespace Email\Rector;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Type\ObjectType;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rewrites the removed `Email\ParseOptions` mutating setters to their immutable
 * `withX()` builders — but only where that rewrite is provably equivalent.
 *
 * The 3.x setters mutated a shared instance, so every holder of the object saw
 * the change. `$o = $o->withX(...)` only reproduces that when `$o` is the sole
 * holder. The rule therefore tracks ownership per function scope, in source
 * order: a variable is OWNED once it is assigned from `new ParseOptions(...)`,
 * a `ParseOptions::rfc*()` preset, or a `withX()` chain, and STOPS being owned
 * the moment it escapes — passed as an argument (e.g. `new Parse(null, $o)`),
 * copied to another variable, captured by a closure, or reassigned from an
 * unknown source such as `$parser->getOptions()`. Parameters are never owned.
 *
 *   owned receiver      $o->setX($v)           =>  $o = $o->withX($v)
 *   own property        $this->opts->setX($v)  =>  $this->opts = $this->opts->withX($v)
 *   anything else       left as-is (it fails loudly on 4.0, the setter no longer
 *                       exists) and annotated with a TODO comment explaining the
 *                       manual migration, so the site stays visible in the diff.
 *
 * setMax*Length() is intentionally excluded: its replacement rebuilds a
 * LengthLimits from the other two limits, which needs human context.
 */
final class ParseOptionsSetterToWithRector extends AbstractRector
{
    private const OPTIONS_CLASS = 'Email\ParseOptions';

    /** Attribute set on each setter-call Expression by the ownership pre-pass. */
    private const OWNED_ATTR = 'emailParse.ownedReceiver';

    private const TODO_MARKER = 'TODO email-parse 4.0:';

    /** @var array<string, string> mutating setter => immutable builder */
    private const SETTER_TO_WITH = [
        'setBannedChars' => 'withBannedChars',
        'setSeparators' => 'withSeparators',
        'setUseWhitespaceAsSeparator' => 'withUseWhitespaceAsSeparator',
        'setLengthLimits' => 'withLengthLimits',
    ];

    /** File path the ownership pre-pass last ran for. */
    private ?string $taggedFile = null;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace a removed ParseOptions setter with its withX() builder where the receiver is locally owned; annotate aliased receivers for manual migration',
            [
                new CodeSample(
                    <<<'PHP'
                        $options = new ParseOptions();
                        $options->setBannedChars($chars);
                        PHP,
                    <<<'PHP'
                        $options = new ParseOptions();
                        $options = $options->withBannedChars($chars);
                        PHP,
                ),
            ],
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Expression::class];
    }

    public function refactor(Node $node): ?Node
    {
        /** @var Expression $node */
        $call = $node->expr;
        if (!$call instanceof MethodCall && !$call instanceof NullsafeMethodCall) {
            return null;
        }

        $method = $this->getName($call->name);
        if ($method === null || !isset(self::SETTER_TO_WITH[$method])) {
            return null;
        }

        if (!$this->isObjectType($call->var, new ObjectType(self::OPTIONS_CLASS))) {
            return null;
        }

        $this->tagOwnershipOnce();

        if ($node->getAttribute(self::OWNED_ATTR) === true) {
            $with = new MethodCall($call->var, self::SETTER_TO_WITH[$method], $call->getArgs());
            $node->expr = new Assign($call->var, $with);

            return $node;
        }

        return $this->annotateForManualMigration($node, $method);
    }

    /**
     * Walk the whole file once, in source order, and tag every setter-call
     * statement with whether its receiver is owned at that point.
     */
    private function tagOwnershipOnce(): void
    {
        $path = $this->file->getFilePath();
        if ($this->taggedFile === $path) {
            return;
        }
        $this->taggedFile = $path;

        $rule = $this;
        $visitor = new class ($rule) extends NodeVisitorAbstract {
            /** @var list<array{owned: array<string, true>, params: array<string, true>}> */
            private array $scopes = [];

            public function __construct(private readonly ParseOptionsSetterToWithRector $rule)
            {
                $this->scopes[] = ['owned' => [], 'params' => []];
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure) {
                    $params = [];
                    foreach ($node->params as $param) {
                        if ($param->var instanceof Variable && \is_string($param->var->name)) {
                            $params[$param->var->name] = true;
                        }
                    }
                    $this->scopes[] = ['owned' => [], 'params' => $params];

                    return null;
                }
                if ($node instanceof Class_) {
                    $this->scopes[] = ['owned' => [], 'params' => []];

                    return null;
                }

                // Escapes: once another holder can observe the instance, a local
                // reassignment no longer reproduces the 3.x shared mutation.
                if ($node instanceof Arg && $node->value instanceof Variable) {
                    $this->disown($node->value);
                }
                if ($node instanceof Assign && $node->expr instanceof Variable) {
                    $this->disown($node->expr);
                }

                // Tag setter-call statements with the ownership state *before* them.
                if ($node instanceof Expression) {
                    $call = $node->expr;
                    if ($call instanceof MethodCall || $call instanceof NullsafeMethodCall) {
                        $node->setAttribute(
                            ParseOptionsSetterToWithRector::ownedAttr(),
                            $this->isOwnedReceiver($call->var),
                        );
                    }
                }

                return null;
            }

            public function leaveNode(Node $node): ?int
            {
                if ($node instanceof Assign && $node->var instanceof Variable && \is_string($node->var->name)) {
                    if ($this->rule->isOwningExpression($node->expr)) {
                        $this->scopes[\count($this->scopes) - 1]['owned'][$node->var->name] = true;
                    } else {
                        $this->disown($node->var);
                    }
                }
                if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure || $node instanceof Class_) {
                    array_pop($this->scopes);
                }

                return null;
            }

            private function isOwnedReceiver(Expr $receiver): bool
            {
                if ($receiver instanceof PropertyFetch) {
                    // Only the object's own property: `$this->options = $this->options->withX()`.
                    return $receiver->var instanceof Variable && $receiver->var->name === 'this';
                }
                if (!$receiver instanceof Variable || !\is_string($receiver->name)) {
                    return false;
                }
                $scope = $this->scopes[\count($this->scopes) - 1];

                return isset($scope['owned'][$receiver->name]) && !isset($scope['params'][$receiver->name]);
            }

            private function disown(Variable $variable): void
            {
                if (\is_string($variable->name)) {
                    unset($this->scopes[\count($this->scopes) - 1]['owned'][$variable->name]);
                }
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($this->file->getNewStmts());
    }

    /**
     * Whether an expression produces a fresh, unshared ParseOptions instance.
     *
     * @internal Called from the ownership visitor.
     */
    public function isOwningExpression(Expr $expr): bool
    {
        if ($expr instanceof New_) {
            return $this->isName($expr->class, self::OPTIONS_CLASS);
        }
        if ($expr instanceof StaticCall) {
            return $this->isName($expr->class, self::OPTIONS_CLASS);
        }
        if ($expr instanceof MethodCall) {
            $name = $this->getName($expr->name);

            return $name !== null
                && str_starts_with($name, 'with')
                && $this->isObjectType($expr->var, new ObjectType(self::OPTIONS_CLASS));
        }

        return false;
    }

    /** @internal Called from the ownership visitor. */
    public static function ownedAttr(): string
    {
        return self::OWNED_ATTR;
    }

    private function annotateForManualMigration(Expression $stmt, string $setter): ?Expression
    {
        $comments = $stmt->getAttribute(AttributeKey::COMMENTS) ?? [];
        foreach ($comments as $comment) {
            if ($comment instanceof Comment && str_contains($comment->getText(), self::TODO_MARKER)) {
                return null; // already annotated on a previous run
            }
        }

        $with = self::SETTER_TO_WITH[$setter];
        $comments[] = new Comment(sprintf(
            "// %s %s() was removed and this ParseOptions is not owned here (a parameter,\n"
            . "// a ->getOptions() result, or already shared). ParseOptions is immutable: configure it where\n"
            . "// it is created, e.g. new Parse(\$logger, ParseOptions::rfc5322()->%s(...)). See UPGRADE.md.",
            self::TODO_MARKER,
            $setter,
            $with,
        ));
        $stmt->setAttribute(AttributeKey::COMMENTS, $comments);

        return $stmt;
    }
}
