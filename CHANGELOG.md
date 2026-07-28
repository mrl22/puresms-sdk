# Changelog

All notable changes to this project are documented in this file.

## [1.0.0] - Unreleased

### Added

- Initial single-class PHP client for PureSMS single and batch SMS sending.
- Scheduled message and batch cancellation.
- Constant-time PureSMS webhook signature verification.
- Static `PureSms::formatNumber()` phone-number formatter, with `toE164()` as an equivalent alias, UK defaults, and an optional country calling code.
- Composer metadata, PHPUnit coverage, documentation, and GitHub Actions CI.
