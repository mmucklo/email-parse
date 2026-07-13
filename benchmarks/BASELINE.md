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
- **Config:** 1000 revs, 5 iterations, 2 warmup, 5% retry threshold
- **After** the `mb_str_split` main-loop optimization (see CHANGELOG). Figures
  below are ~10–27% faster than the pre-optimization v3.3.2 baseline.

| Subject | time/parse | rstdev |
|---|---|---|
| `benchSimpleAsciiAddress` | 86 μs | ±1.4% |
| `benchSimpleAsciiAddressArrayApi` | 82 μs | ±2.5% |
| `benchNameAddr` | 104 μs | ±2.1% |
| `benchUtf8LocalPart` | 98 μs | ±2.0% |
| `benchIdnDomain` | 105 μs | ±2.3% |
| `benchObsRoute` | 81 μs | ±1.2% |
| `benchInvalidAddress` | 56 μs | ±1.7% |
| `benchCommentExtraction` | 114 μs | ±2.8% |
| `benchBatch10Comma` | 541 μs | ±1.2% (≈54 μs/addr) |
| `benchBatch100StreamCount` | 9,783 μs | ±3.5% (≈98 μs/addr) |
