# Security Policy

![Libraries.io dependency status for GitHub repo](https://img.shields.io/librariesio/github/infocyph/InterMix)

## Supported Versions

| InterMix Version | PHP Version | Security Updates |
| ---------------- | ----------- | ---------------- |
| 2.x              | 8.3+        | :white_check_mark: |
| 1.x              | 8.0 - 8.2   | :x: |
| < 1.0            | < 8.0       | :x: |

## Reporting a Vulnerability

Please report security vulnerabilities privately.

- Subject: `SECURITY: infocyph/intermix - <short title>`
- Include:
  - affected version
  - impact summary
  - reproduction steps or PoC
  - suggested fix (if available)

Please do not open a public GitHub issue for unpatched vulnerabilities.

## Security Notes

- `ValueSerializer::decode()` / `ValueSerializer::unserialize()` should only process trusted payloads.
- For untrusted transport channels, enable payload signing:

```php
use Infocyph\InterMix\Serializer\ValueSerializer;

ValueSerializer::setPayloadSigningKey($_ENV['INTERMIX_SIGNING_KEY']);
```
