# Benchmark Baseline

Reference numbers for the PhpBench suite (`benchmarks/ParseBench.php`), used to
catch performance regressions across changes. **Absolute times are hardware- and
PHP-version-specific** — treat the shape and relative cost of the subjects as the
signal, not the raw microseconds, and always compare against a baseline recorded
on the *same* machine.

## How to use

```bash
composer bench            # run the suite, print a report (no comparison)
composer bench:baseline   # record/overwrite the local `baseline` tag
composer bench:compare    # run the suite and diff each subject vs `baseline`
```

The baseline is stored in `.phpbench/` (git-ignored — machine-specific wall-clock
times are not portable). Re-record it on your machine before relying on
`bench:compare`. Runner settings (iterations, warmup, retry threshold) live in
`phpbench.json`; per-subject rev counts in the benchmark class.

## Reference run

Recorded values below are for context only — regenerate locally to compare.

- **Host:** Intel Core i7-8550U @ 1.80GHz, Linux 6.18 (WSL2)
- **PHP:** 8.1.34 · **PhpBench:** 1.4.3
- **Commit:** `1073d4c` (v3.3.2)
- **Config:** 1000 revs, 5 iterations, 2 warmup, 5% retry threshold

| Subject | Mode (time/parse) | rstdev |
|---|---|---|
| `benchSimpleAsciiAddress` | 103.7 μs | ±1.4% |
| `benchSimpleAsciiAddressArrayApi` | 91.0 μs | ±2.5% |
| `benchNameAddr` | 137.3 μs | ±2.1% |
| `benchUtf8LocalPart` | 130.4 μs | ±2.0% |
| `benchIdnDomain` | 119.2 μs | ±2.3% |
| `benchObsRoute` | 108.7 μs | ±1.2% |
| `benchInvalidAddress` | 71.1 μs | ±1.7% |
| `benchCommentExtraction` | 152.5 μs | ±2.8% |
| `benchBatch10Comma` | 682.0 μs | ±1.2% (≈68 μs/addr) |
| `benchBatch100StreamCount` | 13,414.9 μs | ±3.5% (≈134 μs/addr) |

The per-address cost at batch scale (`benchBatch100StreamCount`) is dominated by
the main-loop `mb_substr($emails, $i, 1, $encoding)` call — see the ROADMAP
performance item on a pure-ASCII byte-iteration fast path.
