# Contributing to PHPOutbox

First off, thank you for considering contributing to PHPOutbox! It's people like you that make this package such a great tool for the PHP community.

To maintain a high standard of quality, we follow specific guidelines and processes for contributions. Please read through these before submitting your pull requests.

## Code of Conduct

By participating in this project, you agree to abide by our Code of Conduct (standard contributor covenant). Please be respectful and professional in all interactions.

## How to Contribute

### 1. Reporting Bugs

- Check if the bug is already reported in the [Issues](https://github.com/phpoutbox/outbox/issues) tracker.
- If not, create a new issue. Provide as much detail as possible, including:
    - PHP version and framework version (if applicable).
    - Steps to reproduce the bug.
    - Expected vs. actual behavior.
    - Relevant stack traces or logs.

### 2. Suggesting Enhancements

- Open a new issue with the tag `enhancement`.
- Describe the feature, why it's needed, and how it should work.
- Provide examples of use cases.

### 3. Submitting Pull Requests

- **Branching**: Create a feature branch from `main`. Use descriptive names like `feat/new-publisher` or `fix/pdo-connection`.
- **Atomic Commits**: Keep your commits small and focused. Each commit should do one thing.
- **Tests**: Every new feature or bug fix **must** be accompanied by tests (PHPUnit).
- **Types**: We enforce strict type hinting for all parameters, return types, and properties.
- **Documentation**: Update [README.md](README.md) or the [docs/](docs/) directory if your change introduces new behavior or configuration.

## Development Setup

To set up the development environment, ensure you have PHP 8.2+ and Composer installed.

```bash
# Clone the repository
git clone https://github.com/your-username/phpoutbox-outbox.git
cd phpoutbox-outbox

# Install dependencies
composer install
```

## Coding Standards

We aim for production-grade, well-structured code.

1. **PSR Compliance**: We follow [PSR-12](https://www.php-fig.org/psr/psr-12/) / [PER Coding Style](https://www.php-fig.org/per/coding-style/).
2. **Strict Typing**: Use `declare(strict_types=1);` in every PHP file. Use native types everywhere possible (including PHP 8.2+ `readonly` properties).
3. **Static Analysis**: We use [PHPStan](https://phpstan.org/) at level 8+.
4. **Code Formatting**: Use PHP CS Fixer to format your code before committing.

### Useful Commands

```bash
# Run all tests
./vendor/bin/phpunit

# Run unit tests
./vendor/bin/phpunit --testsuite=Unit

# Run static analysis
./vendor/bin/phpstan analyse

# Fix code style
./vendor/bin/php-cs-fixer fix
```

## Pull Request Process

1. Ensure all tests pass.
2. Ensure PHPStan reports zero errors.
3. Update the `CHANGELOG.md` with a summary of your changes under the `[Unreleased]` section.
4. Fill out the PR template (if available) with a clear description of your changes.
5. A maintainer will review your PR and provide feedback. Once approved, it will be merged into `main`.

---

Thank you for your contribution!
