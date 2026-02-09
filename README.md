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

Email\Parse is a multiple (and single) batch email address parser that is reasonably RFC822 / RFC2822 compliant.

It parses a list of 1 to n email addresses separated by space, comma, or semicolon (configurable).

Installation:
-------------
Add this line to your composer.json "require" section:

### composer.json
```json
    "require": {
       ...
       "mmucklo/email-parse": "*"
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

#### ParseOptions Constructor

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

#### RFC Compliance Modes

The parser supports multiple RFC compliance levels to balance strict validation with backward compatibility:

```php
use Email\Parse;
use Email\ParseOptions;
use Email\RfcMode;

// STRICT_INTL: Full internationalization with UTF-8 support (RFC 6531/6532)
$options = new ParseOptions(
    [],
    [','],
    true,
    null,
    RfcMode::STRICT_INTL,  // RFC mode
    true                    // Allow SMTPUTF8
);
$parser = new Parse(null, $options);
$result = $parser->parse('müller@münchen.de', false);  // Valid UTF-8 address

// STRICT_ASCII: Strict ASCII-only validation (RFC 5322 strict)
$options = new ParseOptions([], [','], true, null, RfcMode::STRICT_ASCII);
$parser = new Parse(null, $options);

// NORMAL: Balanced mode with obsolete syntax support (RECOMMENDED)
$options = new ParseOptions([], [','], true, null, RfcMode::NORMAL);
$parser = new Parse(null, $options);

// RELAXED: Maximum compatibility (RFC 2822)
$options = new ParseOptions([], [','], true, null, RfcMode::RELAXED);
$parser = new Parse(null, $options);

// LEGACY: Current parser behavior (default for v2.x)
$options = new ParseOptions([], [','], true, null, RfcMode::LEGACY);
$parser = new Parse(null, $options);
```

**Mode Comparison:**

| Mode | Standard | UTF-8 Support | Obsolete Syntax | Use Case |
|------|----------|---------------|-----------------|----------|
| `STRICT_INTL` | RFC 6531/6532 | ✅ Full (NFC normalization) | ❌ No | International apps with UTF-8 emails |
| `STRICT_ASCII` | RFC 5322 Strict | ❌ ASCII only | ❌ No | Modern ASCII-only applications |
| `NORMAL` | RFC 5322 + obsolete | ❌ ASCII only | ✅ Yes | **Recommended default** (v3.0+) |
| `RELAXED` | RFC 2822 | ❌ ASCII only | ✅ Permissive | Legacy system integration |
| `LEGACY` | Current behavior | Via flag | ✅ Yes | **Current default** (v2.x) |

**STRICT_INTL Mode Features:**
- UTF-8 characters in local-part and domain (e.g., `日本語@example.jp`)
- Unicode normalization (NFC per RFC 6532 §3.1)
- C0/C1 control character rejection (RFC 6530 §10.1)
- Internationalized domains (IDN) with A-label/U-label support
- Length limits in octets (multi-byte UTF-8 counts as multiple octets)
- Requires PHP Intl extension for full functionality

**Example:**
```php
// UTF-8 email address validation
$options = new ParseOptions([], [','], true, null, RfcMode::STRICT_INTL, true);
$parser = new Parse(null, $options);

$result = $parser->parse('José.García@españa.es', false);
// Valid: UTF-8 characters allowed in STRICT_INTL mode

$result = $parser->parse('.user@example.com', false);
// Invalid: Leading dot not allowed (dot-atom restrictions still apply)
```

#### Configuring Length Limits

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

#### Supported Separators

- **Comma (`,`)** - Configured via `$separators` parameter
- **Semicolon (`;`)** - Configured via `$separators` parameter  
- **Whitespace (space, tab, newlines)** - Controlled by `$useWhitespaceAsSeparator` parameter
- **Mixed separators** - All configured separators work together seamlessly

**Note:** When `useWhitespaceAsSeparator` is `false`, whitespace is still properly cleaned up and names with spaces (like "John Doe") continue to work correctly.

#### Internationalized Domains (IDN)

The parser supports internationalized domain names per RFC 5890/5891. Unicode domains are normalized to ASCII (punycode) for validation and length enforcement, while the original Unicode domain is preserved.

```php
$result = Parse::getInstance()->parse('user@bücher.de', false);
// $result['domain'] = 'bücher.de'
// $result['domain_ascii'] = 'xn--bcher-kva.de'
```

IDN normalization is applied in strict mode as long as the resulting punycode domain is RFC-compliant.

#### Comment Extraction

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

Notes:
======
This should be RFC 2822 compliant, although it will let a few obsolete RFC 822 addresses through such as `test"test"test@xyz.com` (note the quoted string in the middle of the address, which may be obsolete as of RFC 2822).  However it wont allow escaping outside of quotes such as `test@test@xyz.com`.  This would have to be written as `"test@test"@xyz.com`

Here are a few other examples:

```
"John Q. Public" <johnpublic@xyz.com>
this.is.an.address@xyz.com
how-about-an-ip@[10.0.10.2]
how-about-comments(this is a comment!!)@xyz.com
```

#### Function Spec ####

```php
/**
 * function parse($emails, $multiple = true, $encoding = 'UTF-8')
 * @param string $emails List of Email addresses separated by configured separators (comma, semicolon, whitespace by default)
 * @param bool $multiple (optional, default: true) Whether to parse for multiple email addresses or not
 * @param string $encoding (optional, default: 'UTF-8')The encoding if not 'UTF-8'
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
                        'domain_ascii' => string|null, // punycode ASCII domain if IDN normalization applied
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
            'domain_ascii' => string|null, // punycode ASCII domain if IDN normalization applied
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
          'ip' => '',
          'invalid' => false,
          'invalid_reason' => '',
          'comments' => []);

 $emails = "testing@[10.0.10.45] testing@xyz.com, testing-"test...2"@xyz.com (comment)";
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
                    'ip' => '',
                    'invalid' => false,
                    'invalid_reason' => '',
                    'comments' => ['comment'])
                )
            );
```
