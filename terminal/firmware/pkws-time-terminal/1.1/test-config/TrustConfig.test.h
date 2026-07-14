#pragma once

// Public test verification key only. It is accepted exclusively by the
// PlatformIO test environment and must never be used for a production flash.
#define PKWS_TRUST_CONFIGURED 1
static const char TRUST_SIGNING_PUBLIC_KEY[] PROGMEM = R"pem(-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEncr5j2ZH+MWRe4ADSTTntAVzwk6F
Zihj785uzKn7mja+OpjPVMPRk9JS50DUeM8KK8j3JGXTzz5NhNTjRg3NvA==
-----END PUBLIC KEY-----
)pem";
