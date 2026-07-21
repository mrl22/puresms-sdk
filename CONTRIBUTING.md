# Contributing

Thanks for improving this unofficial PureSMS client.

1. Open an issue before making a significant public-API change.
2. Keep the single-production-class architecture and PHP 8.1 support intact.
3. Add or update PHPUnit coverage for changed behaviour.
4. Run `composer validate --strict`, `composer lint`, and `composer test` before opening a pull request.
5. Update the README, reference documents and changelog when public behaviour or project knowledge changes.

Never include real phone numbers, API keys, webhook signatures, or message content from production systems in issues, commits, or tests.
