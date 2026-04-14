# Contributing to Workerman Bundle

Thank you for your interest in contributing to this project!

## Branch Protection Rules

This repository uses branch protection rules on the `master` branch to ensure code quality:

### Required Status Checks

All pull requests must pass the following checks before merging:

- **Lint** - Code style validation using PHP-CS-Fixer, PHPStan, and Rector
- **Tests** - PHPUnit tests across multiple PHP (8.2-8.5) and Symfony (6.4-8.0) versions

### Pull Request Requirements

**Required:**
- At least **1 approving review** is required before merge

**Recommended:**
- All conversations should be resolved before merging
- Branch should be up to date with `master` before merging

## Development Workflow

### Pre-Push Hook

A pre-push git hook is automatically installed via Composer's post-install scripts. It runs `composer lint` before each push to catch issues early.

**To skip the hook** (for emergency pushes):
```bash
git push --no-verify
```

**To manually reinstall the hook**:
```bash
php bin/install-git-hook.php
```

**To remove the hook**:
```bash
rm .git/hooks/pre-push
```

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

4. Update CHANGELOG.md:
   - Add entry under `[Unreleased]` section
   - Follow [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format
   - Include issue number (e.g., `(#65)`)
   - Use appropriate section: `Added`, `Changed`, `Fixed`, `Removed`, or `Deprecated`

### CI Configuration

The CI workflow (`.github/workflows/tests.yaml`) runs on every pull request:

- **Lint job**: Validates `composer.json`, runs security audit, and checks code style
- **Tests job**: Runs PHPUnit tests across the supported PHP (8.2–8.5) and Symfony (6.4–8.0) version matrix

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
