email-parse
===========

[![Support on Patreon](https://img.shields.io/badge/Patreon-Support%20Me-f96854?logo=patreon)](https://www.patreon.com/cw/MatthewJMucklo)

[![CI](https://github.com/mmucklo/email-parse/workflows/CI/badge.svg)](https://github.com/mmucklo/email-parse/actions)
[![codecov](https://codecov.io/gh/mmucklo/email-parse/branch/master/graph/badge.svg)](https://codecov.io/gh/mmucklo/email-parse)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/mmucklo/email-parse/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/mmucklo/email-parse/?branch=master)
[![Latest Stable Version](https://img.shields.io/packagist/v/mmucklo/email-parse.svg)](https://packagist.org/packages/mmucklo/email-parse)
[![Total Downloads](https://img.shields.io/packagist/dt/mmucklo/email-parse.svg)](https://packagist.org/packages/mmucklo/email-parse)
[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue)](https://www.php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Email\Parse is a batch email address parser with configurable RFC compliance levels (RFC 5322, RFC 6531/6532, RFC 2822). Supports internationalized email addresses (UTF-8 local parts and IDN domains).

It parses a list of 1 to n email addresses separated by space, comma, or semicolon (configurable).

Installation:
-------------

```bash
composer require mmucklo/email-parse
```

Usage:
------

### Basic Usage

```php
use Email\Parse;

$result = Parse::getInstance()->parse("a@aaa.com b@bbb.com");
```

### Advanced Usage with ParseOptions

You can configure separator behavior and other parsing options using `ParseOptions`:

```php
use Email\Parse;
use Email\ParseOptions;

// Example 1: Use comma and semicolon as separators (default behavior includes whitespace)
$options = new ParseOptions([], [',', ';']);
$parser = new Parse(null, $options);
$result = $parser->parse("a@aaa.com; b@bbb.com, c@ccc.com");

// Example 2: Disable whitespace as separator (only comma and semicolon work)
$options = new ParseOptions([], [',', ';'], false);
$parser = new Parse(null, $options);
$result = $parser->parse("a@aaa.com; b@bbb.com"); // Works - uses semicolon
$result = $parser->parse("a@aaa.com b@bbb.com");  // Won't split - whitespace not a separator

// Example 3: Names with spaces always work regardless of whitespace separator setting
$options = new ParseOptions([], [',', ';'], false);
$parser = new Parse(null, $options);
$result = $parser->parse("John Doe <john@example.com>, Jane Smith <jane@example.com>");
// Returns 2 valid emails with names preserved
```

### RFC Compliance Presets

The parser provides factory methods on `ParseOptions` for common RFC compliance levels:

```php
use Email\Parse;
use Email\ParseOptions;

// RFC 5321 — Strict ASCII (SMTP Mailbox syntax)
$options = ParseOptions::rfc5321();
$parser = new Parse(null, $options);

// RFC 6531 — Strict Internationalized (full UTF-8 + NFC normalization)
$options = ParseOptions::rfc6531();
$parser = new Parse(null, $options);
$result = $parser->parse('müller@münchen.de', false);  // Valid UTF-8 address

// RFC 5322 — Standard with obsolete syntax support (recommended)
$options = ParseOptions::rfc5322();
$parser = new Parse(null, $options);

// RFC 2822 — Maximum compatibility
$options = ParseOptions::rfc2822();
$parser = new Parse(null, $options);

// Legacy — v2.x default behavior
$options = new ParseOptions();
$parser = new Parse(null, $options);
```

**Preset Comparison:**

| Preset | Standard | UTF-8 Support | Obsolete Syntax | Use Case |
|--------|----------|---------------|-----------------|----------|
| `rfc6531()` | RFC 6531/6532 | Full (NFC normalization) | No | International apps with UTF-8 emails |
| `rfc5321()` | RFC 5321 | ASCII only | No | Modern ASCII-only SMTP validation |
| `rfc5322()` | RFC 5322 + obsolete | ASCII only | Yes | **Recommended default** (v3.0+) |
| `rfc2822()` | RFC 2822 | ASCII only | Permissive | Legacy system integration |
| `new ParseOptions()` | Legacy | Permissive | Yes | **v2.x backward-compatible default** |

**RFC 6531 Features (`rfc6531()`):**
- UTF-8 characters in local-part and domain (e.g., `日本語@example.jp`)
- Unicode normalization (NFC per RFC 6532 §3.1)
- C0/C1 control character rejection (RFC 6530 §10.1)
- Internationalized domains (IDN) with punycode output (`includeDomainAscii = true`)
- Length limits in octets (multi-byte UTF-8 counts as multiple octets)
- Requires PHP Intl extension for full functionality

**Example:**
```php
// UTF-8 email address validation
$options = ParseOptions::rfc6531();
$parser = new Parse(null, $options);

$result = $parser->parse('José.García@españa.es', false);
// Valid: UTF-8 characters allowed in rfc6531() preset

$result = $parser->parse('.user@example.com', false);
// Invalid: Leading dot not allowed (dot-atom restrictions still apply)
```

### Customizing Rules

Each preset sets a combination of boolean rule properties. You can override any of them after creating a preset:

```php
$options = ParseOptions::rfc6531();
$options->requireFqdn = false;          // Allow single-label domains
$options->includeDomainAscii = false;   // Don't output punycode domain
$parser = new Parse(null, $options);
```

**Available Rule Properties:**

| Property | Default | Description |
|----------|---------|-------------|
| **Local-Part Rules** | | |
| `allowUtf8LocalPart` | `true` | Allow UTF-8 characters in local-part (RFC 6531) |
| `allowObsLocalPart` | `false` | Allow obsolete syntax: leading/trailing/consecutive dots (RFC 5322 §4.4) |
| `allowQuotedString` | `true` | Allow quoted-string form in local-part |
| `validateQuotedContent` | `false` | Validate qtext/quoted-pair rules in quoted strings |
| `rejectEmptyQuotedLocalPart` | `false` | Reject `""@domain` (RFC 5321 errata 5414) |
| **Domain Rules** | | |
| `allowUtf8Domain` | `true` | Allow Unicode (U-label) domain names (RFC 5890/5891) |
| `allowDomainLiteral` | `true` | Allow `[IP]` form in domain (RFC 5321 §4.1.3) |
| `requireFqdn` | `false` | Require at least two domain labels (RFC 5321 §2.3.5) |
| `validateIpGlobalRange` | `true` | Validate IP addresses are in global range |
| **Character Validation** | | |
| `rejectC0Controls` | `false` | Reject C0 control characters U+0000-U+001F (RFC 5321) |
| `rejectC1Controls` | `false` | Reject C1 control characters U+0080-U+009F (RFC 6530) |
| `applyNfcNormalization` | `false` | Apply NFC Unicode normalization (RFC 6532 §3.1) |
| **Length & Output** | | |
| `enforceLengthLimits` | `true` | Enforce RFC 5321 length limits (64/254/63) |
| `includeDomainAscii` | `false` | Include punycode `domain_ascii` in output |

The defaults shown above are for `new ParseOptions()` (legacy). Each factory method sets its own combination — see the [source code](src/ParseOptions.php) for exact values.

### ParseOptions Constructor

```php
/**
 * @param array $bannedChars Array of characters to ban from email addresses (e.g., ['%', '!'])
 * @param array $separators Array of separator characters (default: [','])
 * @param bool $useWhitespaceAsSeparator Whether to treat whitespace/newlines as separators (default: true)
 * @param LengthLimits|null $lengthLimits Email length limits. Uses RFC defaults if not provided
 */
public function __construct(
    array $bannedChars = [],
    array $separators = [','],
    bool $useWhitespaceAsSeparator = true,
    ?LengthLimits $lengthLimits = null
)
```

### Configuring Length Limits

You can customize RFC 5321 length limits using the `LengthLimits` class:

```php
use Email\Parse;
use Email\ParseOptions;
use Email\LengthLimits;

// Use default RFC-compliant limits (64, 254, 63)
$options = new ParseOptions([], [','], true, LengthLimits::createDefault());

// Use relaxed limits for legacy systems (128, 512, 128)
$options = new ParseOptions([], [','], true, LengthLimits::createRelaxed());

// Custom limits
$limits = new LengthLimits(
    100,  // maxLocalPartLength (before @)
    300,  // maxTotalLength (entire email)
    100   // maxDomainLabelLength (each domain label)
);
$options = new ParseOptions([], [','], true, $limits);
$parser = new Parse(null, $options);
```

**Default RFC Limits:**
- Local part (before `@`): 64 octets (RFC 5321)
- Total email length: 254 octets (RFC erratum 1690)
- Domain label: 63 characters (RFC 1035)

### Supported Separators

- **Comma (`,`)** - Configured via `$separators` parameter
- **Semicolon (`;`)** - Configured via `$separators` parameter
- **Whitespace (space, tab, newlines)** - Controlled by `$useWhitespaceAsSeparator` parameter
- **Mixed separators** - All configured separators work together seamlessly

**Note:** When `useWhitespaceAsSeparator` is `false`, whitespace is still properly cleaned up and names with spaces (like "John Doe") continue to work correctly.

### Internationalized Domains (IDN)

The parser supports internationalized domain names per RFC 5890/5891. Unicode domains are normalized to ASCII (punycode) for validation and length enforcement, while the original Unicode domain is preserved.

The `domain_ascii` field is included in the output when `includeDomainAscii` is `true` on the `ParseOptions` instance. This is enabled by default in the `rfc6531()` preset.

```php
$options = ParseOptions::rfc6531();
$parser = new Parse(null, $options);
$result = $parser->parse('user@bücher.de', false);
// $result['domain'] = 'bücher.de'
// $result['domain_ascii'] = 'xn--bcher-kva.de'
```

### Comment Extraction

RFC 5322 allows comments in email addresses using parentheses. The parser automatically extracts these comments and returns them in the `comments` array:

```php
use Email\Parse;

// Single comment
$result = Parse::getInstance()->parse('john@example.com (home address)', false);
// $result['comments'] = ['home address']

// Multiple comments
$result = Parse::getInstance()->parse('test(comment1)(comment2)@example.com', false);
// $result['comments'] = ['comment1', 'comment2']

// Nested comments
$result = Parse::getInstance()->parse('test@example.com (comment with (nested) parens)', false);
// $result['comments'] = ['comment with (nested) parens']

// No comments
$result = Parse::getInstance()->parse('test@example.com', false);
// $result['comments'] = []
```

Comments are stripped from the `address` field but preserved in `original_address`.

### Migration Guide

**Migrating from v2.x to v3.0:**

```php
// v2.x default (legacy behavior — still works in v3.0)
$parser = Parse::getInstance();

// v3.0 recommended default
$options = ParseOptions::rfc5322();
$parser = new Parse(null, $options);
```

**Key differences between legacy and `rfc5322()`:**
- `rfc5322()` rejects C0 control characters (`rejectC0Controls = true`)
- `rfc5322()` allows obsolete local-part syntax (consecutive dots, etc.)
- `rfc5322()` enforces RFC 5321 length limits
- Legacy mode (`new ParseOptions()`) accepts UTF-8 by default; `rfc5322()` does not

**Adding UTF-8 support:**

```php
// Use the rfc6531() preset for full internationalized email support
$options = ParseOptions::rfc6531();
$parser = new Parse(null, $options);
$result = $parser->parse('müller@münchen.de', false);
```

#### Function Spec ####

```php
/**
 * function parse($emails, $multiple = true, $encoding = 'UTF-8')
 * @param string $emails List of Email addresses separated by configured separators (comma, semicolon, whitespace by default)
 * @param bool $multiple (optional, default: true) Whether to parse for multiple email addresses or not
 * @param string $encoding (optional, default: 'UTF-8') The encoding if not 'UTF-8'
 * @return: see below: */

    if ($multiple):
         array('success' => boolean, // whether totally successful or not
               'reason' => string, // if unsuccessful, the reason why
               'email_addresses' =>
                    array('address' => string, // the full address (not including comments)
                        'original_address' => string, // the full address including comments
                        'simple_address' => string, // simply local_part@domain_part (e.g. someone@somewhere.com)
                         'name' => string, // the name on the email if given (e.g.: John Q. Public), including any quotes
                         'name_parsed' => string, // the name on the email if given (e.g.: John Q. Public), excluding any quotes
                        'local_part' => string, // the local part (before the '@' sign - e.g. johnpublic)
                        'local_part_parsed' => string, // the local part (before the '@' sign - e.g. johnpublic), excluding any quotes
                        'domain' => string, // the domain after the '@' if given (may be Unicode)
                        'domain_ascii' => string|null, // punycode ASCII domain (when includeDomainAscii is true)
                         'ip' => string, // the IP after the '@' if given
                         'domain_part' => string, // either domain or IP depending on what given
                        'invalid' => boolean, // if the email is valid or not
                        'invalid_reason' => string, // if the email is invalid, the reason why
                        'comments' => array), // array of extracted comments (e.g. ['comment1', 'comment2'])
                    array( .... ) // the next email address matched
        )
    else:
        array('address' => string, // the full address (not including comments)
            'original_address' => string, // the full address including comments
            'simple_address' => string, // simply local_part@domain_part
            'name' => string, // the name on the email if given (e.g.: John Q. Public)
            'name_parsed' => string, // the name excluding quotes
            'local_part' => string, // the local part (before the '@' sign - e.g. johnpublic)
            'local_part_parsed' => string, // the local part excluding quotes
            'domain' => string, // the domain after the '@' if given (may be Unicode)
            'domain_ascii' => string|null, // punycode ASCII domain (when includeDomainAscii is true)
            'ip' => string, // the IP after the '@' if given
            'domain_part' => string, // either domain or IP depending on what given
            'invalid' => boolean, // if the email is valid or not
            'invalid_reason' => string, // if the email is invalid, the reason why
            'comments' => array) // array of extracted comments (e.g. ['comment1', 'comment2'])
    endif;
```

Other Examples:
---------------
```php
 $email = "\"J Doe\" <johndoe@xyz.com>";
 $result = Email\Parse->getInstance()->parse($email, false);

 $result == array('address' => '"JD" <johndoe@xyz.com>',
          'original_address' => '"JD" <johndoe@xyz.com>',
          'simple_address' => 'johndoe@xyz.com',
          'name' => '"JD"',
          'name_parsed' => 'J Doe',
          'local_part' => 'johndoe',
          'local_part_parsed' => 'johndoe',
          'domain_part' => 'xyz.com',
          'domain' => 'xyz.com',
          'domain_ascii' => null,
          'ip' => '',
          'invalid' => false,
          'invalid_reason' => '',
          'comments' => []);

 $emails = "testing@[10.0.10.45] testing@xyz.com, testing-\"test...2\"@xyz.com (comment)";
 $result = Email\Parse->getInstance()->parse($emails);
 $result == array(
            'success' => true,
            'reason' => null,
            'email_addresses' =>
                array(
                array(
                    'address' => 'testing@[10.0.10.45]',
                    'original_address' => 'testing@[10.0.10.45]',
                    'simple_address' => 'testing@[10.0.10.45]',
                    'name' => '',
                    'name_parsed' => '',
                    'local_part' => 'testing',
                    'local_part_parsed' => 'testing',
                    'domain_part' => '10.0.10.45',
                    'domain' => '',
                    'domain_ascii' => null,
                    'ip' => '10.0.10.45',
                    'invalid' => false,
                    'invalid_reason' => '',
                    'comments' => []),
                array(
                    'address' => 'testing@xyz.com',
                    'original_address' => 'testing@xyz.com',
                    'simple_address' => 'testing@xyz.com',
                    'name' => '',
                    'name_parsed' => '',
                    'local_part' => 'testing',
                    'local_part_parsed' => 'testing',
                    'domain_part' => 'xyz.com',
                    'domain' => 'xyz.com',
                    'domain_ascii' => null,
                    'ip' => '',
                    'invalid' => false,
                    'invalid_reason' => '',
                    'comments' => []),
                array(
                    'address' => '"testing-test...2"@xyz.com',
                    'original_address' => 'testing-"test...2"@xyz.com (comment)',
                    'simple_address' => 'testing-test...2@xyz.com',
                    'name' => '',
                    'name_parsed' => '',
                    'local_part' => '"testing-test...2"',
                    'local_part_parsed' => 'testing-test...2',
                    'domain_part' => 'xyz.com',
                    'domain' => 'xyz.com',
                    'domain_ascii' => null,
                    'ip' => '',
                    'invalid' => false,
                    'invalid_reason' => '',
                    'comments' => ['comment'])
                )
            );
```
