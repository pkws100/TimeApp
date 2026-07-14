#pragma once

// Set to 1 only after both placeholders have been replaced.
#define PKWS_PROVISIONING_CONFIGURED 0

/*
 * Copy to ProvisioningConfig.local.h and replace both values per terminal or
 * provisioning batch. The local file is ignored by Git and must never be
 * committed. Use WPA2-safe AP and portal passwords with at least 12 characters.
 */
static const char PKWS_SETUP_AP_PASSWORD[] = "change-me-setup";
static const char PKWS_PORTAL_ADMIN_PASSWORD[] = "change-me-portal";
