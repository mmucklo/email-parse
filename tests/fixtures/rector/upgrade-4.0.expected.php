<?php
namespace App;

use Email\Parse;
use Email\ParseOptions;

// owned: fresh instances and a withX() chain
$a = new ParseOptions();
$a = $a->withBannedChars(['%']);
$b = ParseOptions::rfc5322();
$b = $b->withSeparators([';']);
$c = ParseOptions::rfc5321()->withRequireFqdn(false);
$c = $c->withUseWhitespaceAsSeparator(false);

// escape by argument: after new Parse(null, $d) the parser shares $d
$d = new ParseOptions();
$parser = new Parse(null, $d);
// TODO email-parse 4.0: setSeparators() was removed and this ParseOptions is not owned here (a parameter,
// a ->getOptions() result, or already shared). ParseOptions is immutable: configure it where
// it is created, e.g. new Parse($logger, ParseOptions::rfc5322()->withSeparators(...)). See UPGRADE.md.
$d->setSeparators([';']);

// escape by copy
$e = new ParseOptions();
$f = $e;
// TODO email-parse 4.0: setBannedChars() was removed and this ParseOptions is not owned here (a parameter,
// a ->getOptions() result, or already shared). ParseOptions is immutable: configure it where
// it is created, e.g. new Parse($logger, ParseOptions::rfc5322()->withBannedChars(...)). See UPGRADE.md.
$e->setBannedChars(['!']);

// aliased: not created here
$g = $parser->getOptions();
// TODO email-parse 4.0: setLengthLimits() was removed and this ParseOptions is not owned here (a parameter,
// a ->getOptions() result, or already shared). ParseOptions is immutable: configure it where
// it is created, e.g. new Parse($logger, ParseOptions::rfc5322()->withLengthLimits(...)). See UPGRADE.md.
$g->setLengthLimits(new \Email\LengthLimits(64, 254, 63));

function configure(ParseOptions $p): void
{
    // TODO email-parse 4.0: setBannedChars() was removed and this ParseOptions is not owned here (a parameter,
    // a ->getOptions() result, or already shared). ParseOptions is immutable: configure it where
    // it is created, e.g. new Parse($logger, ParseOptions::rfc5322()->withBannedChars(...)). See UPGRADE.md.
    $p->setBannedChars(['%']);      // parameter: never owned
    $q = new ParseOptions();
    $q = $q->withSeparators([',']);        // owned inside the function
}

final class Holder
{
    private ParseOptions $opts;
    public function tune(): void
    {
        $this->opts = $this->opts->withSeparators([';']);   // own property
    }
}

// getters, incl. nullsafe; getMax* must stay
$x = $a->bannedChars;
$y = $a?->separators;
$z = $a->getMaxLocalPartLength();
$s = new \Email\Parse();
