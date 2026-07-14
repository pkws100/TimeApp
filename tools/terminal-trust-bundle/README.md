# Terminal Trust-Bundle Tool

This offline CLI creates the public JSON served by `GET /api/v1/terminal/trust-bundle`. It signs deterministic payload JSON with OpenSSL ECDSA P-256/SHA-256. The private key is supplied only at runtime by `--private-key` or `TERMINAL_TRUST_BUNDLE_PRIVATE_KEY`; never place it in this repository or on a terminal.

```bash
php tools/terminal-trust-bundle/terminal-trust-bundle.php sign \
  --private-key /secure/pkws-terminal-bundle-private.pem \
  --version 2 --cert /secure/ca-current.pem --cert /secure/ca-next.pem \
  --warning-after 2029-01-01T00:00:00Z --replace-before 2029-07-01T00:00:00Z \
  --output storage/app/terminal-trust-bundle.json

php tools/terminal-trust-bundle/terminal-trust-bundle.php verify \
  --public-key /secure/pkws-terminal-bundle-public.pem \
  --bundle storage/app/terminal-trust-bundle.json
```

The firmware's public P-256 key in `1.1/include/TrustConfig.h` must be the matching public key before production release. Compile a new firmware only for public-key rotation; normal CA changes use a newer signed bundle.
