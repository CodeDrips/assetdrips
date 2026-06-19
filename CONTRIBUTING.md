# Contributing to AssetDrips

Thanks for your interest in improving AssetDrips. This guide covers how to set up
a development environment, the standards we follow, and how to propose changes.

> Found a security vulnerability? **Do not open a public issue.** Follow the
> [Security Policy](SECURITY.md) instead.

## Getting set up

```bash
git clone https://github.com/codedrips/assetdrips.git
cd assetdrips
composer install          # dev dependencies: PHPUnit, PHP_CodeSniffer + WPCS
```

The unit test suite runs standalone (it stubs the WordPress functions it needs),
so you do **not** need a WordPress install or a database to develop most changes:

```bash
composer test             # PHPUnit unit suite (fast, no WP required)
composer lint             # PHP_CodeSniffer against WordPress Coding Standards
composer lint:fix         # auto-fix what PHPCBF can
```

For changes that touch real WordPress behaviour, an integration suite is available
via `bin/install-wp-tests.sh` and `composer test:integration` (requires a MySQL
test database).

## Requirements

- PHP 8.1+
- Composer
- WordPress 6.0+ (only for integration tests / manual testing)

## How we work

- **Coding standard:** WordPress Coding Standards (enforced by PHP_CodeSniffer;
  config in `phpcs.xml.dist`). New and changed code must be clean.
  > Note: the codebase carries some pre-existing PHPCS debt. Please lint the files
  > and lines **you change** to a clean state; you don't need to fix unrelated
  > pre-existing warnings elsewhere in a file.
- **Architecture:** code is PSR-4 under `AssetDrips\` in `src/`, organised by
  module (`Index`, `Admin`, `Sort`, `Squeeze`, `Usage`, `Quarantine`, `Scan`,
  `Coverage`, `Score`, `Db`, `Cli`). Prefer reusing the existing index and
  reversibility patterns over adding new ones.
- **Tests:** add or update unit tests for behaviour you change. Bug fixes should
  include a test that fails before the fix and passes after.
- **Non-destructive by default:** any feature that modifies or removes a file must
  be reversible (quarantine or a verified backup). This is a core project value.

## Pull requests

1. Fork the repo and create a branch from `main`
   (`fix/...`, `feat/...`, `chore/...`).
2. Make your change with tests; keep commits focused.
3. Run `composer test` and `composer lint` locally — both should pass for your
   changed code.
4. Open a pull request describing **what** changed and **why**. Link any related
   issue. Fill in the PR template.

We use clear, conventional commit subjects (e.g. `fix(squeeze): …`,
`feat(find): …`, `docs: …`). Small, reviewable PRs merge fastest.

## Reporting bugs and requesting features

Use the GitHub issue templates. For bugs, please include your WordPress and PHP
versions, your image editor (ImageMagick/GD) and its WebP/AVIF support if relevant,
steps to reproduce, and what you expected versus what happened.

## License

By contributing, you agree that your contributions will be licensed under the
project's [GPL-2.0-or-later](LICENSE) license.
