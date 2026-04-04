# Contributing to Workerman Bundle

Thank you for your interest in contributing to this project!

## Branch Protection Rules

This repository uses branch protection rules on the `master` branch to ensure code quality:

### Required Status Checks

All pull requests must pass the following checks before merging:

- **Lint** - Code style validation using PHP-CS-Fixer, PHPStan, and Rector
- **Tests** - PHPUnit tests across multiple PHP (8.2-8.5) and Symfony (6.4-8.0) versions

### Pull Request Requirements

- At least **1 approving review** is required before merge
- All conversations must be resolved
- Branch must be up to date with `master` before merging

## Development Workflow

### Before Submitting a PR

1. Run linting locally:
   ```bash
   composer lint
   ```

2. Run tests locally:
   ```bash
   composer test
   ```

3. Ensure all checks pass before pushing

### CI Configuration

The CI workflow (`.github/workflows/tests.yaml`) runs on every pull request:

- **Lint job**: Validates `composer.json`, runs security audit, and checks code style
- **Tests job**: Runs PHPUnit tests across the supported PHP and Symfony version matrix

## Code Standards

- PHP 8.2+ syntax
- Follow PSR-12 coding standards (enforced by PHP-CS-Fixer)
- Static analysis with PHPStan level 6
- Automated refactoring with Rector

## Reporting Issues

Please use GitHub Issues to report bugs or request features. Include:

- Clear description of the problem
- Steps to reproduce
- Expected vs actual behavior
- PHP and Symfony versions
