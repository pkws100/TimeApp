#pragma once

#include <cstring>
#include <cstdlib>

enum class QueueFailureAction {
    RETRY_TEMPORARY,
    BLOCK_GLOBAL_KEEP_ACTIVE,
    DEAD_LETTER_RECORD,
    CONFIRMED
};

inline bool terminalCodeEquals(const char *actual, const char *expected)
{
    return actual != nullptr && std::strcmp(actual, expected) == 0;
}

inline unsigned long retryAfterMilliseconds(const char *value)
{
    if (value == nullptr || *value == '\0') return 0;
    char *end = nullptr;
    unsigned long seconds = std::strtoul(value, &end, 10);
    if (end == value || *end != '\0') return 0;
    if (seconds < 1) seconds = 1;
    if (seconds > 900) seconds = 900;
    return seconds * 1000UL;
}

inline QueueFailureAction queueFailureActionFor(int status, const char *code)
{
    if (status >= 200 && status < 300) return QueueFailureAction::CONFIRMED;
    if (status <= 0 || status == 408 || status == 425 || status == 429 || status >= 500) {
        return QueueFailureAction::RETRY_TEMPORARY;
    }
    if (status == 401 || status == 403
        || terminalCodeEquals(code, "terminal_auth_required")
        || terminalCodeEquals(code, "terminal_auth_failed")
        || terminalCodeEquals(code, "terminal_disabled")
        || terminalCodeEquals(code, "terminal_unknown")
        || terminalCodeEquals(code, "terminal_ip_denied")
        || terminalCodeEquals(code, "terminal_storage_missing")
        || terminalCodeEquals(code, "feature_disabled")) {
        return QueueFailureAction::BLOCK_GLOBAL_KEEP_ACTIVE;
    }
    if (terminalCodeEquals(code, "nfc_tag_invalid")
        || terminalCodeEquals(code, "nfc_tag_not_found")
        || terminalCodeEquals(code, "employee_mapping_invalid")
        || terminalCodeEquals(code, "nfc_uid_missing")
        || terminalCodeEquals(code, "invalid_uid")
        || terminalCodeEquals(code, "unknown_tag")
        || terminalCodeEquals(code, "unassigned_tag")) {
        return QueueFailureAction::DEAD_LETTER_RECORD;
    }
    return QueueFailureAction::BLOCK_GLOBAL_KEEP_ACTIVE;
}

enum class TrustRecoveryAction {
    KEEP_VALID_ACTIVE,
    RESTORE_PREVIOUS,
    RESTORE_OLD_PENDING,
    USE_FACTORY,
    FAIL_SAFE_FACTORY
};

inline TrustRecoveryAction trustRecoveryActionFor(
    bool markerPresent,
    bool markerValid,
    bool activeValid,
    bool activeIsCandidate,
    bool previousSafe,
    bool oldPendingSafe
) {
    if (markerPresent && !markerValid) return TrustRecoveryAction::FAIL_SAFE_FACTORY;
    if (markerPresent) {
        if (activeValid && !activeIsCandidate) return TrustRecoveryAction::KEEP_VALID_ACTIVE;
        if (oldPendingSafe) return TrustRecoveryAction::RESTORE_OLD_PENDING;
        if (previousSafe) return TrustRecoveryAction::RESTORE_PREVIOUS;
        return TrustRecoveryAction::USE_FACTORY;
    }
    if (activeValid) return TrustRecoveryAction::KEEP_VALID_ACTIVE;
    if (previousSafe) return TrustRecoveryAction::RESTORE_PREVIOUS;
    if (oldPendingSafe) return TrustRecoveryAction::RESTORE_OLD_PENDING;
    return TrustRecoveryAction::USE_FACTORY;
}
