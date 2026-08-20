# Cookbook

Practical recipes for `mmucklo/email-parse`. Every snippet is self-contained — assume `require 'vendor/autoload.php';` and `use Email\{Parse, ParseOptions};` at the top.

- [Parse one address](#parse-one-address)
- [Parse many addresses](#parse-many-addresses)
- [Stream a large batch](#stream-a-large-batch)
- [RFC presets](#rfc-presets)
- [Handle failures (reason, code, severity)](#handle-failures-reason-code-severity)
- [Internationalized (UTF-8 / IDN) addresses](#internationalized-utf-8--idn-addresses)
- [Canonical form and round-tripping](#canonical-form-and-round-tripping)
- [Normalize the local part (Gmail dots, `+tag`)](#normalize-the-local-part-gmail-dots-tag)
- [Detect look-alike (homograph) domains](#detect-look-alike-homograph-domains)
- [Custom separators and banned characters](#custom-separators-and-banned-characters)
- [Reuse a configured parser](#reuse-a-configured-parser)
- [The legacy array API](#the-legacy-array-api)

---

## Parse one address

`parseSingle()` returns a typed, immutable `ParsedEmailAddress`.

```php
$result = (new Parse())->parseSingle('"John Q. Public" <john@example.com>');

$result->invalid;         // false
$result->localPart;       // 'john'
$result->domain;          // 'example.com'
$result->nameParsed;      // 'John Q. Public'
$result->simpleAddress;   // 'john@example.com'
$result->comments;        // []
```

## Parse many addresses

`parseMultiple()` splits a string of comma/space-separated addresses and returns a `ParseResult`.

```php
$result = (new Parse())->parseMultiple('a@a.com, b@b.com, not-an-email');

$result->success;                        // false (one address failed)
count($result->emailAddresses);          // 3
$result->emailAddresses[0]->domain;      // 'a.com'
$result->emailAddresses[2]->invalid;     // true

foreach ($result->emailAddresses as $addr) {
    if (!$addr->invalid) {
        echo $addr->simpleAddress, "\n";
    }
}
```

## Stream a large batch

For mailing-list-sized input, `parseStream()` yields one `ParsedEmailAddress` at a time (any `iterable` of strings) without building the whole result set in memory.

```php
$lines = new SplFileObject('addresses.txt'); // one address per line
$parser = new Parse();

$valid = 0;
foreach ($parser->parseStream($lines) as $addr) {
    if (!$addr->invalid) {
        $valid++;
    }
}
```

## RFC presets

Start from a preset and adjust with fluent `withX()` builders (each returns a new, immutable `ParseOptions`).

```php
ParseOptions::rfc5321();  // strict ASCII SMTP mailbox
ParseOptions::rfc5322();  // RFC 5322 message-format addresses
ParseOptions::rfc6531();  // internationalized (EAI) — UTF-8 local parts + IDN
ParseOptions::rfc2822();  // permissive / obsolete-syntax friendly

// Example: RFC 6531 but don't require a fully-qualified domain.
$opts = ParseOptions::rfc6531()->withRequireFqdn(false);
$parser = new Parse(null, $opts);
```

## Handle failures (reason, code, severity)

Invalid results carry a human string **and** a structured `ParseErrorCode` enum, so you can branch programmatically without string matching. `invalidSeverity()` distinguishes *structural* failures from *policy* ones.

```php
use Email\ParseErrorCode;
use Email\ValidationSeverity;

$r = (new Parse(null, ParseOptions::rfc5321()))->parseSingle('user@[10.0.0.1]');

$r->invalid;                 // true
$r->invalidReason;           // 'IP address invalid: ...global range'
$r->invalidReasonCode;       // ParseErrorCode::IpNotInGlobalRange
$r->invalidReasonCode->value;// 'ip_not_in_global_range'

// Critical = unparseable / RFC violation; Warning = well-formed but policy-rejected.
if ($r->invalidSeverity() === ValidationSeverity::Warning) {
    // e.g. a private-range IP or non-FQDN domain — safe to accept in non-SMTP contexts.
}

// React to a specific code:
if ($r->invalidReasonCode === ParseErrorCode::LocalPartTooLong) {
    // ...
}
```

## Internationalized (UTF-8 / IDN) addresses

`rfc6531()` accepts UTF-8 local parts and U-label (Unicode) domains. Ask for the punycode (A-label) form with `withIncludeDomainAscii()`.

```php
$opts = ParseOptions::rfc6531()
    ->withRequireFqdn(false)
    ->withIncludeDomainAscii(true);

$r = (new Parse(null, $opts))->parseSingle('用户@münchen.de');

$r->invalid;      // false
$r->localPart;    // '用户'
$r->domain;       // 'münchen.de'  (U-label)
$r->domainAscii;  // 'xn--mnchen-3ya.de'  (A-label / punycode)
```

## Canonical form and round-tripping

`canonical()` returns a minimally-quoted RFC 5322 form that re-parses to an equivalent address. Invalid addresses canonicalize to `''`.

```php
$r = (new Parse())->parseSingle('"John Doe" <john@example.com>');
$r->canonical();  // 'John Doe <john@example.com>'  (quotes dropped — not needed)

$r2 = (new Parse())->parseSingle('"a b"@example.com');
$r2->canonical(); // '"a b"@example.com'  (space needs quoting — kept)
```

## Normalize the local part (Gmail dots, `+tag`)

Pass a `localPartNormalizer` closure — `fn(string $localPart, string $domain): string` — invoked after validation succeeds. Handy for account-deduplication.

```php
$gmail = static function (string $local, string $domain): string {
    if (in_array(strtolower($domain), ['gmail.com', 'googlemail.com'], true)) {
        $local = strtok($local, '+');       // drop +tag
        $local = str_replace('.', '', $local); // dots are insignificant
    }
    return $local;
};

$opts = ParseOptions::rfc5322()->withLocalPartNormalizer($gmail);
$parser = new Parse(null, $opts);

$parser->parseSingle('John.Doe+news@gmail.com')->localPart; // 'johndoe'
$parser->parseSingle('john.doe@example.com')->localPart;    // 'john.doe' (untouched)
```

## Detect look-alike (homograph) domains

Opt in with `withDetectConfusableDomain()` to flag mixed-script "look-alike" domains — e.g. `аpple.com` where the `а` is Cyrillic (U+0430). This is a **security-policy signal, not RFC validity**: the address stays valid; the result carries `domainIsSuspicious`. Legitimate single-script international domains (`почта.рф`, `münchen.de`) are **not** flagged. Requires the `intl` extension.

```php
$opts = ParseOptions::rfc6531()->withRequireFqdn(false)->withDetectConfusableDomain(true);
$parser = new Parse(null, $opts);

$r = $parser->parseSingle('user@аpple.com'); // Cyrillic 'а'
$r->invalid;             // false — still syntactically valid
$r->domainIsSuspicious;  // true  — flag it in your own policy layer

$parser->parseSingle('user@apple.com')->domainIsSuspicious; // false
$parser->parseSingle('user@почта.рф')->domainIsSuspicious;  // false (single-script, legit)
```

## Custom separators and banned characters

The first three positional constructor arguments configure batch splitting.

```php
// Only split on comma and semicolon (not whitespace); ban '%' and '!'.
$opts = new ParseOptions(
    bannedChars: ['%', '!'],
    separators: [',', ';'],
    useWhitespaceAsSeparator: false,
);

$parser = new Parse(null, $opts);
$parser->parseMultiple('a@a.com; b@b.com');   // 2 addresses
$parser->parseSingle('pct%40@example.com')->invalid; // true — '%' banned
```

## Reuse a configured parser

`ParseOptions` and `Parse` are cheap to build but immutable — construct once and reuse across many calls (the parser also caches a Spoofchecker across a batch when confusable detection is on).

```php
$parser = new Parse(null, ParseOptions::rfc5322());

foreach ($addresses as $raw) {
    $r = $parser->parseSingle($raw);
    // ...
}
```

Prefer explicit instantiation; `Parse::getInstance()` (a singleton with default options) exists for convenience and backward compatibility.

## The legacy array API

`parse()` returns the original array shape — `parse($input, multiple: false)` for one address, `true` for many. Typed objects expose the same data via `->toArray()`.

```php
$arr = (new Parse())->parse('john@example.com', false);
$arr['local_part']; // 'john'
$arr['domain'];     // 'example.com'
$arr['invalid'];    // false

// Equivalent typed access:
$obj = (new Parse())->parseSingle('john@example.com');
$obj->toArray() === $arr; // same shape (invalid_reason_code is a ParseErrorCode enum)
```
