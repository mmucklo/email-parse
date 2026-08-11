# Roadmap

Future plans by version. Items here are intent, not commitment — priority and scope may shift.

## Deprecation Timeline

### v3.0 — shipped
- [x] `LengthLimits` switched to readonly constructor promotion (getters/setters removed; see [UPGRADE.md](UPGRADE.md) for migration).
- [x] `ParseOptions` setters marked `@deprecated v3.0` (`setBannedChars`, `setSeparators`, `setUseWhitespaceAsSeparator`, `setLengthLimits`, `setMaxLocalPartLength`, `setMaxTotalLength`, `setMaxDomainLabelLength`) — still functional.
- [x] `RfcMode` class never released (existed only on a feature branch).

### v4.0 — planned
- [ ] Remove all `@deprecated` `ParseOptions` setters above.
- [ ] Make remaining private fields (`bannedChars`, `separators`, `useWhitespaceAsSeparator`, `lengthLimits`) public readonly via constructor promotion.

## v3.1 — Immutable Config, Error Codes, Typed Output — shipped

**Immutable `ParseOptions` with fluent builders:**
- [x] All 15 boolean rule properties are now `readonly` (PHP 8.1). The 4 state fields (`bannedChars`, `separators`, `useWhitespaceAsSeparator`, `lengthLimits`) remain mutable via deprecated setters until v4.0.
- [x] Fluent builder methods that return new instances:
  ```php
  ParseOptions::rfc5322()->withBannedChars([...])->withSeparators([...])->withRequireFqdn(true);
  ```
- Deprecated setters continue to work for backward compatibility.

**Structured error codes:**
- [x] `ParseErrorCode` backed enum — 46 cases grouped by category (structural, character, dot placement, local-part content, quoted-string, domain, IP literal, length, display-name).
- [x] `invalid_reason_code: ?ParseErrorCode` on every parsed-address entry, populated alongside the existing `invalid_reason` string.

**Typed output value objects (non-breaking):**
- [x] `ParsedEmailAddress` — readonly properties for every per-address field with named-arg constructor and `fromArray()` factory.
- [x] `ParseResult` — readonly `success`, `reason`, `emailAddresses` (array of `ParsedEmailAddress`).
- [x] New methods: `Parse::parseSingle(string): ParsedEmailAddress`, `Parse::parseMultiple(string): ParseResult`.
- Existing `parse()` stays unchanged for backward compatibility.

**Additional validation rules:**
- [x] `validateDisplayNamePhrase: bool` — enforce RFC 5322 §3.2.5 phrase syntax (atext + WSP only) for unquoted display names.
- [x] `strictIdna: bool` — apply full IDNA2008 conformance (`IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_ASCII`) per RFC 5891/5892/5893. Enabled by default in `rfc6531()`.
- [x] Extended test coverage: 265 assertions (target: 250+).

## v3.2 — Streaming, Severity Levels, Obsolete Syntax — shipped

**Batch streaming:**
- [x] `Parse::parseStream(iterable, string): Generator<ParsedEmailAddress>` — yields one typed address at a time; each input item may itself contain multiple separator-delimited addresses.

**Validation severity levels:**
- [x] `ValidationSeverity` enum with `Critical`, `Warning`, `Info` cases.
- [x] `ParseErrorCode::severity()` method classifying every code (13 Warning, rest Critical).
- [x] `ParsedEmailAddress::invalidSeverity()` accessor returning the derived severity (or `null` when valid).

**Obsolete syntax extensions (RFC 5322 §4):**

> Note: `obs-local-part` was already supported via `allowObsLocalPart` in v3.0.

- [x] `obs-route` handling — `ParseOptions::$allowObsRoute` gates acceptance of `<@host1,@host2:user@host3>` source-route prefixes; the route is captured on `ParsedEmailAddress::$obsRoute`. Enabled by default in `rfc5322()` and `rfc2822()`.
- [x] `obs-angle-addr` — implied by obs-route support (it is the outer `[CFWS] "<" obs-route addr-spec ">" [CFWS]` form).
- [x] `obs-domain-list` — the `*("," [CFWS] ["@" domain])` shape is consumed inside `STATE_OBS_ROUTE`.
- [x] CFWS (comments / folding whitespace) improvements — look-ahead in the whitespace handler now absorbs CFWS at dot-atom boundaries (`local @domain`, `local@ domain`, `local @ domain`) and around angle-addr delimiters (`<  local@domain  >`, `<local @ domain>`), including folded whitespace (LF + WSP). Comments in these positions were already supported in v3.0.

## v3.3 — Polish, Ergonomics — shipped

Non-breaking follow-on to v3.2.

**Serialization ergonomics:**
- [x] `ParsedEmailAddress::toArray(): array<string, mixed>` — round-trips to the legacy array shape for callers mixing typed and array-based code.
- [x] `ParsedEmailAddress::toJson(int $flags = 0): string` — convenience wrapper over `json_encode` with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
- [x] `implements \Stringable` on `ParsedEmailAddress` — returns `simpleAddress` for valid addresses; empty string otherwise. Drops directly into string contexts.
- [x] `ParseResult::toArray()` and `toJson()` counterparts.

**Canonicalization (pulled forward from v4.0):**
- [x] `ParsedEmailAddress::canonical(): string` — minimal-quoting RFC 5322 display form per §3.2.4 (local-part) and §3.2.5 (phrase).
- [x] Optional local-part normalizer callback on `ParseOptions` for domain-specific rules (Gmail dot-insensitivity, `+tag` plus-addressing). Attached via `withLocalPartNormalizer(?callable)`.

**Ecosystem bridges:** *(deferred — out of scope for v3.3 per user direction)*
- [ ] `mmucklo/email-parse-symfony` — Symfony `Constraint` + `ConstraintValidator` attribute. Wraps existing `ParseOptions` presets.
- [ ] `mmucklo/email-parse-laravel` — Laravel validation rule, service provider for DI.
- [ ] PSR-14 event dispatcher integration — emit a `ParsedAddressEvent` per result for observability.

## Quality and Infrastructure (ongoing)

Not tied to a specific release; picked up as time allows.

**Testing depth:**
- [~] Mutation testing with Infection — wired in via `composer infect` with thresholds `minMsi=80`, `minCoveredMsi=85` (current baseline, up from 74/79). Target remains ≥85% overall MSI; raise threshold as more error-path tests land.
- [x] Property-based testing — `tests/PropertyTest.php` with 10 invariants across 200 random iterations each: no-crash on arbitrary bytes, determinism, reason+code consistency, severity classification, Stringable contract, toArray ↔ parse() round-trip, valid-address round-trip, and all-presets-never-crash. No extra dependency (native PHPUnit + `mt_rand`; deterministic via `SEED` envvar).
- [~] Parse.php line coverage — now 87.98% (up from 86.69%). Overall project line coverage 91.15% (up from 89.61%). Remaining gaps are obscure error branches, the "shouldn't ever get here" default case, and code paths reachable only via internal state corruption. Target ≥95% aspirational.
- [x] CI matrix: PHP 8.5 added as a required job; PHP 8.6 added as an allowed-to-fail experimental (nightly) job until its stable release (~Nov 2026).

**RFC conformance (gold-standard differential):**

Differential testing against the `dominicsayers/isemail` reference corpus (164 cases) drove the strict-preset false-accept set from 29 down to **1** — the intentional trailing root dot, now toggleable. All clusters resolved:

- [x] **Quoted-string boundaries** — `"test"test@` / `"test""test"@` rejected (`AtextAfterQuotedString`); `"word".atom` stays valid.
- [x] **Unclosed domain literal** — `test@[1.2.3.4` rejected; end-of-input unterminated-delimiter check keyed on parser state.
- [x] **Comment (CFWS) parsing** — unbalanced nested comment; backslash quoted-pair (`(comment\)test@` — `\)` no longer closes); C0 controls in comment content (`ControlCharInComment`); atext splitting one atom after a comment (`AtextAfterComment`).
- [x] **Quoted-string content** — C0 controls (bare CR/LF) in a quoted string rejected under the strict presets.
- [x] **CR/LF & folding-whitespace** — resolved via the whitespace policy: single-address mode rejects surrounding/dangling CR/LF by default (`withTrimSingleAddressWhitespace` loosens); multi-address mode stays loose by default with an opt-in `withStrictMultiWhitespace` for per-address strictness. Whitespace still separates addresses in batch mode.
- [x] **Trailing domain dot** — `test@iana.org.` accepted by default (RFC 5321 §2.3.5); `withRejectTrailingDot(true)` rejects it. The one remaining corpus divergence, by design.

The comparison harness remains a local dev tool (not a CI gate). Every fixed cluster carries regression tests in `tests/ParseTest.php`.

**Pre-existing bugs (found during review; not in the isemail corpus, so not covered above):**
- [ ] **Angle-addr with a domain-literal rejected.** `<user@[1.2.3.4]>` and `<user@[IPv6:…]>` are valid RFC 5322 name-addr forms, but the `>` handler requires `subState == STATE_DOMAIN` and a closing `]` leaves it at `STATE_AFTER_DOMAIN`, so the `>` is reported as `MissingDomainBeforeClosingAngle`. The bare `user@[1.2.3.4]` is accepted — found by a metamorphic "angle-wrap should preserve validity" check. Present on `master`.
- [ ] **`word "." word` with quoted-string words over-rejected.** A quoted-string dot quoted-string (`"x"."y"@iana.org`) is a legal `obs-local-part` (RFC 5322 §3.4.1: `word *("." word)`, `word = atom / quoted-string`), but the parser rejects it. Present on `master`, unrelated to the conformance work. `"x".y` (quoted-string then atom) and `x."y"` (atom then quoted-string) are affected too.
- [ ] **`ParserConfusion` error code leaks to users.** The `parser_confusion` reason/code is an internal "shouldn't happen" marker, but the over-rejection above surfaces it as a user-facing result. Whatever the resolution of the previous item, no valid-or-invalid *input* should ever produce `ParserConfusion` — replace these paths with a specific reason or fix the underlying handling.
- [ ] **C1 controls (U+0080–U+009F) not rejected in comment content.** The ctext control-char check is single-byte only, so 2-byte-UTF-8 C1 controls slip through. No active gap under the current presets (`rejectC1Controls` is off in `rfc5322()`), but the check should honor `rejectC1Controls` for comments the way it already does for local parts and quoted strings.

**Static analysis:**
- [x] PHPStan level 6 → 8 — tighter generics and inference; required four small nullable-return guards (`idn_to_ascii`, `mb_split`, `file_get_contents`) and one local docblock shape on `parseMultiple()`.
- [x] Psalm alongside PHPStan — level 3 with baseline (66 entries, all false positives or duplicates of PHPStan findings). Found no genuinely new bugs vs PHPStan level 8; serves as a cross-check for future regressions. `composer psalm`.

**Performance:**
- [x] PhpBench suite — `benchmarks/ParseBench.php` covers single ASCII, name-addr, UTF-8 local-part, IDN, obs-route, 10-address comma batch, 100-address `parseStream` batch, invalid inputs, and comment extraction. Run with `composer bench`.
- [x] Benchmark baseline + regression comparison — `composer bench:baseline` records a tagged reference (5 iterations, 5% retry threshold for stable numbers); `composer bench:compare` diffs a run against it. Reference figures and host context in `benchmarks/BASELINE.md`. Local storage (`.phpbench/`) is git-ignored since wall-clock times are machine-specific.
- [x] Wire `bench:compare` into CI — a non-blocking `benchmarks` job records a baseline from the PR base's `src/` and compares the head against it on the same runner. Generous 50%-regression assertion (shared runners are noisy) and `continue-on-error`, so it reports without blocking.
- [x] Main-loop hot path — replaced per-character `mb_substr($emails, $i, 1)` (O(n²) for multi-byte encodings, which rescan from the start each call) with a single `mb_str_split()` pass and array indexing. ~10–27% faster across the suite; biggest gains on longer inputs. Measured against the baseline via `composer bench:compare`.
- [ ] Further profiling under mailing-list-sized inputs if needed — the `mb_str_split` array now dominates memory for very large batches; a streaming/chunked reader could bound that.

**Maintainability / readability:**
- [ ] **Reorganize `Parse::parse()` for readability.** The main state machine has grown deeply nested (a `switch ($state)` with a nested `switch/if` on `$subState`, plus per-character CFWS/comment/quote handling), and several correctness fixes have added flags and edge branches that are hard to follow. Decompose the loop body into named per-state handlers (e.g. `handleTrim`/`handleAddress`/`handleQuote`/`handleComment`) so each state's logic is isolated and independently readable. Also fold the accumulated tracking flags (`after_closing_quote`, `comment_after_local_atext`, `comment_escaped`, …) into a clearer per-parse context object.
  - **Hard constraint: no performance regression.** Benchmark before and after with `composer bench:baseline` (on the pre-refactor commit) then `composer bench:compare` on the refactor; every subject must stay within noise. A prior spike proved this is achievable — decomposing the switch into method-per-character dispatch dropped `parse()` cyclomatic complexity 168 → 23 with **no measurable slowdown** (PHP 8's method calls are cheap; smaller methods can even help I-cache). Prefer passing a context object over instance properties, to keep the parser reentrant (a user `localPartNormalizer` callback can re-enter `parse()`).
  - Keep it behavior-preserving: it is a pure structural refactor, gated by the full test suite (currently 99 tests) + PHPStan level 8 + Psalm, with no changes to parsing logic, conditions, or ordering.

**Community / documentation:**
- [x] `CONTRIBUTING.md` — dev setup, all `composer` scripts, test-case guidance, code-style rules, RFC citation expectations.
- [ ] GitHub issue + pull-request templates.
- [ ] `CODE_OF_CONDUCT.md`.
- [ ] Examples directory or GitHub Pages cookbook (UTF-8 addresses, obs-route in practice, custom normalizers once they ship, Symfony/Laravel integration snippets).
- [ ] README cleanup — split the large reference tables into `docs/` sub-pages if the top-level README grows further.

## v4.0 — Breaking Modernization

**API cleanup:**
- [ ] Remove deprecated `ParseOptions` setters (see Deprecation Timeline above).
- [ ] Remove `parse()` in favor of `parseSingle()` / `parseMultiple()` with typed returns — eliminates the polymorphic `$multiple` boolean parameter.
- [ ] Deprecate or remove the `getInstance()` singleton (recommend explicit instantiation).
- [ ] Constructor promotion on `ParseOptions` with named arguments.

**New capabilities (genuinely breaking or late-binding):**
- [ ] Optional DNS/MX validation via callback interface (`DnsValidator`). Breaking because the Parse constructor signature grows, and because synchronous DNS lookups change performance characteristics meaningfully.
- [ ] Group syntax support (RFC 6854: `Group Name: addr1, addr2;`). Breaking because it introduces a new output-container shape for grouped results.

*Note: `canonicalize()` and the local-part normalizer callback were moved to v3.3 as additive (non-breaking) features.*
