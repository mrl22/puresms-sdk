# Changelog

All notable changes to this project are documented in this file.

## [1.1.0] - 2026-07-28

### Added

- Static `PureSms::formatNumber()` phone-number formatter, with `toE164()` as an equivalent alias, UK defaults, and an optional country calling code.
- Dependency-free direct-inclusion installation guidance.
- Individual PHP 8.1 through 8.5 GitHub Actions workflows and README status badges.

## [1.0.0] - 2026-07-21

### Added

- Initial single-class PHP client for PureSMS single and batch SMS sending.
- Scheduled message and batch cancellation.
- Constant-time PureSMS webhook signature verification.
- Composer metadata, PHPUnit coverage, documentation, and GitHub Actions CI.
