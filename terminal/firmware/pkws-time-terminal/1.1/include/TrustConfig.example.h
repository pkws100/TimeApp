#pragma once

/*
 * Copy this file to TrustConfig.local.h before a production firmware build.
 * The value is public, but it is intentionally supplied outside Git so that a
 * release cannot silently use an example or test signing key.
 */
static const char TRUST_SIGNING_PUBLIC_KEY[] PROGMEM = R"pem(-----BEGIN PUBLIC KEY-----
REPLACE_WITH_THE_PKWS_PRODUCTION_P256_PUBLIC_KEY
-----END PUBLIC KEY-----
)pem";
