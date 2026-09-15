<?php
namespace App;

use Email\Parse;
use Email\ParseOptions;

// owned: fresh instances and a withX() chain
$a = new ParseOptions();
$a->setBannedChars(['%']);
$b = ParseOptions::rfc5322();
$b->setSeparators([';']);
$c = ParseOptions::rfc5321()->withRequireFqdn(false);
$c->setUseWhitespaceAsSeparator(false);

// escape by argument: after new Parse(null, $d) the parser shares $d
$d = new ParseOptions();
$parser = new Parse(null, $d);
$d->setSeparators([';']);

// escape by copy
$e = new ParseOptions();
$f = $e;
$e->setBannedChars(['!']);

// aliased: not created here
$g = $parser->getOptions();
$g->setLengthLimits(new \Email\LengthLimits(64, 254, 63));

function configure(ParseOptions $p): void
{
    $p->setBannedChars(['%']);      // parameter: never owned
    $q = new ParseOptions();
    $q->setSeparators([',']);        // owned inside the function
}

final class Holder
{
    private ParseOptions $opts;
    public function tune(): void
    {
        $this->opts->setSeparators([';']);   // own property
    }
}

// getters, incl. nullsafe; getMax* must stay
$x = $a->getBannedChars();
$y = $a?->getSeparators();
$z = $a->getMaxLocalPartLength();
$s = Parse::getInstance();
