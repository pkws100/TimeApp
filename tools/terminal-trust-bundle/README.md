# Terminal Trust-Bundle Tool

This offline CLI creates the public JSON served by `GET /api/v1/terminal/trust-bundle`. It signs the shared deterministic length-delimited UTF-8 protocol with OpenSSL ECDSA P-256/SHA-256. The private key is supplied only at runtime by `--private-key` or `TERMINAL_TRUST_BUNDLE_PRIVATE_KEY`; never place it in this repository or on a terminal.

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

Only EC `prime256v1`/P-256 keys are accepted; RSA and other curves fail closed. PEM line endings are normalized to LF before signing. The matching firmware public key must be supplied in ignored `1.1/include/TrustConfig.local.h` before a production build. Compile a new firmware only for public-key rotation; normal CA changes use a newer signed bundle. The explicitly non-production vectors in `tests/fixtures/terminal-trust/` verify cross-language compatibility.
