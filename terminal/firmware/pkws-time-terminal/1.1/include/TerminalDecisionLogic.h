#pragma once

#include <cstdint>
#include <cstdio>
#include <cstring>
#include <cstdlib>
#include <ctime>

static constexpr const char *TERMINAL_BERLIN_POSIX_TZ = "CET-1CEST,M3.5.0/2,M10.5.0/3";
static constexpr const char *TERMINAL_CLOCK_PLACEHOLDER = "--.--.---- --:--";

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

inline bool formatTerminalBerlinClock(time_t epoch, bool timeValid, char *buffer, size_t bufferSize)
{
    if (buffer == nullptr || bufferSize == 0) return false;
    if (!timeValid) {
        std::snprintf(buffer, bufferSize, "%s", TERMINAL_CLOCK_PLACEHOLDER);
        return false;
    }

    struct tm localTime = {};
    if (localtime_r(&epoch, &localTime) == nullptr) {
        std::snprintf(buffer, bufferSize, "%s", TERMINAL_CLOCK_PLACEHOLDER);
        return false;
    }

    return std::strftime(buffer, bufferSize, "%d.%m.%Y %H:%M", &localTime) > 0;
}

inline bool formatTerminalUtcTimestamp(time_t epoch, bool timeValid, char *buffer, size_t bufferSize)
{
    if (buffer == nullptr || bufferSize == 0) return false;
    if (!timeValid) {
        buffer[0] = '\0';
        return false;
    }

    struct tm utcTime = {};
    if (gmtime_r(&epoch, &utcTime) == nullptr) {
        buffer[0] = '\0';
        return false;
    }

    return std::strftime(buffer, bufferSize, "%Y-%m-%dT%H:%M:%SZ", &utcTime) > 0;
}

inline int64_t terminalDaysFromCivil(int year, unsigned month, unsigned day)
{
    year -= month <= 2;
    const int era = (year >= 0 ? year : year - 399) / 400;
    const unsigned yearOfEra = static_cast<unsigned>(year - era * 400);
    const int shiftedMonth = static_cast<int>(month) + (month > 2 ? -3 : 9);
    const unsigned dayOfYear = static_cast<unsigned>((153 * shiftedMonth + 2) / 5) + day - 1;
    const unsigned dayOfEra = yearOfEra * 365 + yearOfEra / 4 - yearOfEra / 100 + dayOfYear;
    return era * 146097 + static_cast<int>(dayOfEra) - 719468;
}

inline time_t terminalUtcTmToEpoch(const struct tm &utc)
{
    const int year = utc.tm_year + 1900;
    const unsigned month = static_cast<unsigned>(utc.tm_mon + 1);
    const unsigned day = static_cast<unsigned>(utc.tm_mday);
    if (month < 1 || month > 12 || day < 1 || day > 31
        || utc.tm_hour < 0 || utc.tm_hour > 23 || utc.tm_min < 0 || utc.tm_min > 59
        || utc.tm_sec < 0 || utc.tm_sec > 60) {
        return 0;
    }
    const int64_t seconds = terminalDaysFromCivil(year, month, day) * 86400
        + utc.tm_hour * 3600 + utc.tm_min * 60 + utc.tm_sec;
    return static_cast<time_t>(seconds);
}

inline bool readyClockRefreshRequired(
    bool readyOrIdleNfcState,
    bool temporaryDisplayActive,
    bool busy,
    const char *previousLine,
    const char *currentLine
) {
    if (!readyOrIdleNfcState || temporaryDisplayActive || busy || currentLine == nullptr) return false;
    return previousLine == nullptr || std::strcmp(previousLine, currentLine) != 0;
}
