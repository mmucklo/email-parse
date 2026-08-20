<!-- Thanks for contributing to mmucklo/email-parse! -->

## Summary

<!-- What does this PR change, and why? One or two sentences. -->

## Type of change

- [ ] Bug fix (parsing/validation correctness)
- [ ] New feature / option (additive)
- [ ] Behavior change (a previously-valid/invalid verdict changes — call it out below)
- [ ] Performance
- [ ] Docs / tests / tooling only

## Behavior impact

<!-- Does this change any valid/invalid verdict, error code, or output shape?
     If yes, describe it and note the migration path. If no, say "No behavior change." -->

## Checklist

- [ ] `composer test` passes (add/adjust tests — parser changes belong in `tests/testspec.yml`; edge cases as dedicated test methods)
- [ ] `composer cs:check` and `composer stan` are clean (run `composer cs:fix` as needed)
- [ ] `CHANGELOG.md` updated under `[Unreleased]` for any user-facing change
- [ ] Parsing/validation changes cite the relevant **RFC section** in a code comment (e.g. RFC 5322 §3.2.4)
- [ ] New public API is documented (docblock + a `docs/cookbook.md` recipe if user-facing)

## Notes for reviewers

<!-- Anything worth flagging: tricky edge cases, benchmark results, follow-ups. -->
