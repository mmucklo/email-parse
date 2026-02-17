# RFC Compliance Design Document

## Relevant Email Address RFCs

### Core Standards
- **RFC 822** (1982) - Original email format standard
- **RFC 2822** (2001) - Updated internet message format
- **RFC 5322** (2008) - Current internet message format standard
- **RFC 5321** (2008) - Simple Mail Transfer Protocol (defines Mailbox syntax)
- **RFC 3696** (2004) - Application Techniques for Checking and Transformation of Names (erratum 1690: 254-octet total limit)

### Internationalization (EAI) Standards
- **RFC 6530** (2012) - Overview and Framework for Internationalized Email
- **RFC 6531** (2012) - SMTP Extension for Internationalized Email (enables UTF-8 when SMTPUTF8 is specified)
- **RFC 6532** (2012) - Internationalized Email Headers (allows UTF-8 in email headers and addresses)

### Domain Name Standards
- **RFC 1035** (1987) - Domain Names: Implementation and Specification (63-octet label limit, 255-octet domain)
- **RFC 1123** (1989) - Requirements for Internet Hosts (domain labels may start with digits)
- **RFC 5890-5893** (2010) - IDNA2008: Internationalized Domain Names in Applications

### Relevant Errata
- **RFC 5322 EID 3135** (held): quoted-string permits empty strings like `""@ietf.org`
- **RFC 5322 EID 4692** (held): qtext intentionally excludes SPACE (%d32)
- **RFC 5321 EID 4315** (held): IPv6 ABNF overly restrictive for some valid compressed forms
- **RFC 5321 EID 5414** (held): Quoted-string should require `1*QcontentSMTP` (non-empty)
- **RFC 3696 EID 1690** (verified): Total address length is 254 octets (256 path - 2 angle brackets)

---

## Design: Merged ParseOptions with Rule Properties

All validation rules are public boolean properties on `ParseOptions`. RFC compliance levels are static factory methods returning pre-configured instances. The constructor signature is unchanged from v2.x for backward compatibility.

- `new ParseOptions()` — legacy v2.x behavior (default)
- `ParseOptions::rfc5321()` — Strict ASCII (RFC 5321 Mailbox syntax)
- `ParseOptions::rfc6531()` — Strict internationalized (RFC 6531/6532, full UTF-8 + NFC)
- `ParseOptions::rfc5322()` — Standard with obsolete syntax (recommended v3.0 default)
- `ParseOptions::rfc2822()` — Maximum compatibility

Override any rule after creating a preset:

```php
$opts = ParseOptions::rfc6531();
$opts->requireFqdn = false;
$opts->includeDomainAscii = false;
$parser = new Parse(null, $opts);
```

### Preset Comparison Table

| Rule | `rfc5321()` | `rfc6531()` | `rfc5322()` | `rfc2822()` | `new()` (legacy) |
|------|:-----------:|:-----------:|:-----------:|:-----------:|:-----------------:|
| `allowUtf8LocalPart` | | x | | | x |
| `allowObsLocalPart` | | | x | x | |
| `allowQuotedString` | x | x | x | x | x |
| `validateQuotedContent` | x | x | | | |
| `rejectEmptyQuotedLocalPart` | x | x | | | |
| `allowUtf8Domain` | | x | | | x |
| `allowDomainLiteral` | x | x | x | x | x |
| `requireFqdn` | x | x | | | |
| `validateIpGlobalRange` | | | x | x | x |
| `rejectC0Controls` | x | x | x | | |
| `rejectC1Controls` | | x | | | |
| `applyNfcNormalization` | | x | | | |
| `enforceLengthLimits` | x | x | x | x | x |
| `includeDomainAscii` | | x | | | |

### Deprecation Plan

**v3.0:**
- `LengthLimits` uses readonly constructor promotion (breaking change for direct property writes)
- `ParseOptions` setters (`setBannedChars`, `setSeparators`, etc.) marked `@deprecated`
- `RfcMode` class deleted (only existed on feature branch, never released)

**v4.0:**
- Remove all deprecated `ParseOptions` setters
- Consider making remaining private fields public readonly with constructor promotion

---

## Edge Cases and Considerations

### Address Parsing vs Email Message Handling
This library focuses on **email address parsing** (addr-spec: `local-part@domain`), not full email message handling. Some RFC requirements apply to SMTP transmission, message headers, or message bodies rather than address syntax validation.

### Length Limits
- All length limits in RFC 5321 are specified in **octets**, not characters
- For UTF-8 addresses, multi-byte characters count as multiple octets
- Example: A 3-byte UTF-8 character counts as 3 octets toward the 64-octet local-part limit

### Dot-Atom Restrictions (strict presets)
- No leading dots: `.user@example.com` is invalid
- No trailing dots: `user.@example.com` is invalid
- No consecutive dots: `user..name@example.com` is invalid
- Obsolete syntax (`rfc5322()`, `rfc2822()`) is more permissive with dots

### Case Sensitivity
- Local-part MUST be treated as case-sensitive per RFCs
- Domain names are case-insensitive per DNS standards

### Control Characters
- C0 control characters (U+0000-U+001F) prohibited per RFC 5321
- C1 control characters (U+0080-U+009F) also prohibited in UTF-8 addresses (RFC 6530 §10.1)
- Backspace (U+0008) explicitly prohibited in mailbox local-parts (RFC 6530 §10.1)

### Quoted-String vs Dot-Atom
- RFC 5322 recommends using dot-atom form when possible
- Quoted-strings required for: spaces, special chars not in atext
- Special characters requiring quoting: ( ) < > [ ] : ; @ \ , . "

### IDNA Domain Handling
- Domains with non-ASCII must use IDNA (RFC 5890/5891)
- Can be stored as U-labels (Unicode) or A-labels (punycode)
- Must convert to A-labels for DNS lookups
- Punycode discouraged when UTF-8 support available (RFC 6530)

### Context-Specific Rules (Not Address Parsing)
The following rules apply to email **transmission/headers** but not address **syntax parsing**:
- **SMTPUTF8 extension**: Required for SMTP transmission of UTF-8 addresses (RFC 6531 §3.1)
- **Header field encoding**: UTF-8 in header field values (RFC 6530 §7.2), but field names remain ASCII
- **Group syntax**: Allowed in From/Sender header fields (RFC 6854), not in addr-spec parsing
- **Line length**: 998 octets for message headers (RFC 6532), not relevant to address syntax

### Optional Network Validation (Future Enhancement)
- **DNS/MX validation**: RFC 5321 requires domains be FQDN resolvable to MX or A/AAAA records
- This is **network-level validation**, separate from syntax parsing
- Could be added as optional flag in a future version

---

## RFC Grammar Differences Explained

### 1. dot-atom vs obs-local-part (consecutive/leading/trailing dots)

```
; RFC 5322 §3.2.3 — STRICT: no leading, trailing, or consecutive dots
dot-atom-text = 1*atext *("." 1*atext)
; Valid:   user.name       (dot between atext groups)
; Invalid: .user  user.  user..name  (dot at boundary or consecutive)

; RFC 5322 §4.4 — obs-local-part: obsolete form allows flexible dots
obs-local-part = word *("." word)
word           = atom / quoted-string
atom           = [CFWS] 1*atext [CFWS]
; In practice, major providers accept consecutive dots (Gmail rewrites them)
```

The strict `dot-atom` rule says each segment between dots must have at least one character. The obsolete `obs-local-part` rule is more permissive. RFC 5322 says parsers MUST accept `obs-local-part` but generators MUST NOT produce it.

### 2. RFC 5321 Mailbox vs RFC 5322 addr-spec

```
; RFC 5322 §3.4.1 — message header syntax (more permissive)
addr-spec    = local-part "@" domain
local-part   = dot-atom / quoted-string / obs-local-part
domain       = dot-atom / domain-literal / obs-domain

; RFC 5321 §4.1.2 — SMTP transport syntax (stricter)
Mailbox      = Local-part "@" ( Domain / address-literal )
Local-part   = Dot-string / Quoted-string    ; NO obs-local-part
Domain       = sub-domain *("." sub-domain)
sub-domain   = Let-dig [Ldh-str]             ; stricter than dot-atom
Ldh-str      = *( ALPHA / DIGIT / "-" ) Let-dig  ; must end with alphanumeric
```

RFC 5322 defines what can appear in email headers — it's intentionally permissive to handle legacy messages. RFC 5321 defines what SMTP servers actually accept — it's stricter. Key differences:

- **Domain labels**: RFC 5321 requires each label to start and end with alphanumeric (`Let-dig`)
- **No obsolete syntax**: RFC 5321 has NO `obs-local-part`
- **Quoted strings**: RFC 5321 uses `QcontentSMTP` (no CFWS wrappers)

The `rfc5321()` and `rfc6531()` presets follow RFC 5321 Mailbox syntax. The `rfc5322()` and `rfc2822()` presets follow RFC 5322 addr-spec.

### 3. qtext vs qtextSMTP (spaces in quoted strings)

```
; RFC 5322 §3.2.4 — qtext does NOT include space
qtext        = %d33 / %d35-91 / %d93-126

; RFC 5321 §4.1.2 — qtextSMTP DOES include space
qtextSMTP    = %d32-33 / %d35-91 / %d93-126

; Both allow backslash-escaped characters via quoted-pair:
quoted-pair     = "\" (VCHAR / WSP)       ; RFC 5322
quoted-pairSMTP = "\" %d32-126            ; RFC 5321
```

`"hello world"@example.com` is valid per RFC 5321 (space allowed in `qtextSMTP`) but technically requires escaping per strict RFC 5322. In practice, all servers accept unescaped spaces in quoted strings.

### 4. C0 vs C1 control characters

```
; C0 controls: U+0000-U+001F (ASCII control chars: NUL, TAB, LF, CR, etc.)
; Excluded from atext, qtext, and qtextSMTP in all RFCs.

; C1 controls: U+0080-U+009F (Latin-1 supplement control chars)
; Valid UTF-8 sequences but NOT meaningful characters.
; RFC 6530 §10.1 explicitly prohibits them in internationalized email.
```

C0 controls are never valid in email addresses. C1 controls are leftovers from Latin-1 encoding — valid UTF-8 but prohibited by RFC 6530. The `rejectC1Controls` rule handles this (enabled in `rfc6531()`, disabled in others).

### 5. IDNA: U-labels vs A-labels

```
; U-label: Unicode form — "münchen.de" (human-readable)
; A-label: ASCII/punycode form — "xn--mnchen-3ya.de" (DNS-compatible)

; RFC 6531 §3.3 extends RFC 5321 sub-domain:
;   sub-domain =/ U-label
```

Internationalized domain names have two representations. U-labels must be NFC-normalized and follow IDNA rules. The `includeDomainAscii` rule controls whether the A-label form appears in parsed output.

---

## Future Enhancements (Post v3.0)

### v3.1 — Validation Refinements
- Enhanced quoted-string validation for strict presets
- Domain-literal syntax validation improvements
- IDNA U-label validation for `rfc6531()` preset
- Extended test coverage (target: 250+ assertions)

### v3.2 — Obsolete Syntax Extensions
- obs-route handling for `rfc5322()` preset
- CFWS (comments/folding whitespace) improvements
- obs-angle-addr support
- obs-domain-list syntax for `rfc2822()` preset

### v4.0 — Advanced Features
- Optional DNS/MX validation flag
- Group syntax support (RFC 6854)
- Full mailbox-list parsing improvements
- Display name parsing enhancements
- Remove deprecated `ParseOptions` setters

---

## Bug Inventory

Bugs found in the feature branch code during RFC compliance audit. Status indicates whether each was fixed during the v3.0 refactor.

### Fixed inherently by the refactor

These bugs existed because of the old `RfcMode` string-constant architecture. Replacing mode-string checks with `ParseOptions` boolean properties eliminated them.

| # | Bug | Root Cause | How Fixed |
|---|-----|-----------|-----------|
| 1 | C1 regex broken (`'/[\x80-\x9F]/u'`) | Raw bytes invalid in `/u` mode | Uses `'/[\x{0080}-\x{009F}]/u'` in unified `validateLocalPart()` |
| 3 | `rfc5321()` allowed UTF-8 via SMTPUTF8 flag | Old code checked `getAllowSmtpUtf8()` | `allowUtf8LocalPart = false` in `rfc5321()` — no leak possible |
| 4 | IP range check only checked one strict mode | `=== RfcMode::STRICT` missed STRICT_INTL | Uses `$opts->validateIpGlobalRange` boolean directly |

### Fixed with explicit code changes

| # | Bug | Description | Fix |
|---|-----|-------------|-----|
| 2 | Escaped quotes never work | Backslash count loop started at `$j = $i` (the `"` itself) instead of `$j = $i - 1` | Changed to `$j = $i - 1`. Pre-existing bug (exists on master). |
| 5 | No quoted-string content validation | All modes returned `true` for quoted strings without checking content | Implemented in unified `validateLocalPart()` when `validateQuotedContent = true` |
| 6 | Empty quoted local-parts accepted | `""@example.com` passed in all modes | Check `rejectEmptyQuotedLocalPart` in `validateLocalPart()` |
| 7 | Length check included enclosing quotes | `strlen($localPart)` counted 2 DQUOTE chars | Use `strlen($emailAddress['local_part_parsed'])` for local-part limit |
| 8 | NORMAL mode passed all UTF-8 unchecked | `validateLocalPartNormal()` returned `true` for any non-ASCII | Unified method checks `allowUtf8LocalPart` first |
| 9 | RELAXED mode accepted control characters | Pattern `[\x01-\x7F]+` included C0 controls | Uses actual `atext` character set; `rejectC0Controls` flag handles explicitly |
| 11 | `normalizeDomainAscii` crashed on empty domain | `max(array_keys(count_chars('', 1)))` throws `ValueError` | Added early return for empty string |
| 12 | Domain set to `null` instead of empty string | Type inconsistency with `buildEmailAddressArray()` | Changed to `$emailAddress['domain'] = ''` |
