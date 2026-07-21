# Release checklist

1. Confirm the public API and documentation are complete, then update `CHANGELOG.md` with the release date and highlights.
2. Run `composer validate --strict`, `composer lint`, and `composer test` locally.
3. Confirm GitHub Actions passes on PHP 8.1 through 8.5.
4. Create a public GitHub repository at `https://github.com/mrl22/puresms-sdk`, push `master`, and create an annotated `v1.0.0` tag.
5. Publish GitHub release notes that link to the changelog and note the package is unofficial.
6. Submit the public repository to Packagist, enable the Packagist GitHub webhook, and confirm `composer require mrl22/puresms-sdk` resolves the tagged release.
7. Re-run the installation snippet in a clean PHP project with `ext-curl` enabled.
