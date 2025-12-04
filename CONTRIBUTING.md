# Contributing to Payments Router

Thank you for considering contributing to Payments Router!

## Code of Conduct

Be respectful and inclusive. We're all here to build something great.

## How to Contribute

### Reporting Bugs

- Use the GitHub issue tracker
- Include detailed steps to reproduce
- Include your PHP and Laravel versions
- Include relevant config and code snippets

### Suggesting Features

- Open an issue with [Feature Request] in the title
- Explain the use case and why it's useful
- Be open to discussion

### Pull Requests

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Add tests for your changes
5. Ensure tests pass (`composer test`)
6. Commit your changes (`git commit -m 'Add amazing feature'`)
7. Push to the branch (`git push origin feature/amazing-feature`)
8. Open a Pull Request

### Coding Standards

- Follow PSR-12 coding standards
- Run `composer format` before committing
- Add PHPDoc blocks for public methods
- Write tests for new features

### Running Tests

```bash
composer test
```

### Adding a New Provider

1. Create driver class extending `AbstractDriver`
2. Implement all `DriverInterface` methods
3. Add configuration to `config/payments.php`
4. Write tests for the driver
5. Update documentation

## Development Setup

```bash
git clone https://github.com/ken-de-nigerian/payzephyr.git
cd payzephyr
composer install
composer test
```

## Questions?

Open a discussion on GitHub or email the maintainers.
