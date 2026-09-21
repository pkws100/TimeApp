#pragma once

#include <cstdint>
#include <cstdio>
#include <cstring>
#include <cstdlib>
#include <ctime>

static constexpr const char *TERMINAL_BERLIN_POSIX_TZ = "CET-1CEST,M3.5.0/2,M10.5.0/3";
static constexpr const char *TERMINAL_CLOCK_PLACEHOLDER = "--.--.---- --:--";
static constexpr time_t TERMINAL_VALID_TIME_AFTER_EPOCH = 1704067200;

inline bool terminalTimeValid(time_t epoch)
{
    return epoch > TERMINAL_VALID_TIME_AFTER_EPOCH;
}

enum class QueueFailureAction {
    RETRY_TEMPORARY,
    BLOCK_GLOBAL_KEEP_ACTIVE,
    DEAD_LETTER_RECORD,
    CONFIRMED
};

enum class ScanFeedbackState {
    WAITING_SERVER,
    SERVER_CONFIRMED,
    SERVER_REJECTED,
    STORED_OFFLINE
};

inline bool terminalDeadlineReached(uint32_t now, uint32_t deadline)
{
    return static_cast<int32_t>(now - deadline) >= 0;
}

inline bool terminalDeadlinePending(uint32_t now, uint32_t deadline)
{
    return !terminalDeadlineReached(now, deadline);
}

inline bool terminalScheduledDeadlineReached(uint32_t now, uint32_t deadline)
{
    return deadline == 0 || terminalDeadlineReached(now, deadline);
}

inline uint32_t terminalMillisecondsUntil(uint32_t now, uint32_t deadline)
{
    return terminalDeadlinePending(now, deadline) ? deadline - now : 0;
}

inline uint32_t queueRetryDeadlineOnSyncEntry(uint32_t now)
{
    return now;
}

inline uint32_t queueRetryWaitMilliseconds(bool active, uint32_t now, uint32_t deadline)
{
    return active ? terminalMillisecondsUntil(now, deadline) : 0;
}

inline bool queuedConfirmationComplete(bool serverConfirmed, bool activeRecordRemoved)
{
    return serverConfirmed && activeRecordRemoved;
}

inline bool scanResponseShouldUpdateLiveFeedback(bool queuedRequest)
{
    return !queuedRequest;
}

inline bool liveScanRetryShouldQueue(uint32_t retryDelayMs, uint32_t maximumForegroundDelayMs)
{
    return retryDelayMs > maximumForegroundDelayMs;
}

inline bool operationDurationExceeded(uint32_t now, uint32_t startedAt, uint32_t maximumDurationMs)
{
    return static_cast<uint32_t>(now - startedAt) >= maximumDurationMs;
}

inline uint32_t extendedScheduledDeadline(uint32_t now, uint32_t currentDeadline, uint32_t requestedDelayMs)
{
    if (currentDeadline != 0 && terminalDeadlinePending(now, currentDeadline)
        && terminalMillisecondsUntil(now, currentDeadline) >= requestedDelayMs) {
        return currentDeadline;
    }
    return now + requestedDelayMs;
}

inline uint32_t persistentNotBeforeDelayMilliseconds(
    uint64_t nowEpochSeconds,
    uint64_t notBeforeEpochSeconds,
    uint32_t maximumDelayMs
)
{
    if (notBeforeEpochSeconds <= nowEpochSeconds) return 0;
    const uint64_t remainingSeconds = notBeforeEpochSeconds - nowEpochSeconds;
    const uint64_t remainingMilliseconds = remainingSeconds * 1000ULL;
    return remainingMilliseconds > maximumDelayMs
        ? maximumDelayMs
        : static_cast<uint32_t>(remainingMilliseconds);
}

inline bool relativeQueueDelayNeedsStart(uint32_t recordSequence, uint32_t trackedSequence)
{
    return recordSequence != trackedSequence;
}

inline bool scanSendDue(uint32_t now, uint32_t sendAt)
{
    return terminalDeadlineReached(now, sendAt);
}

inline bool serverResponseConfirmsBooking(int httpStatus, bool jsonParsed, bool responseOk)
{
    return httpStatus >= 200 && httpStatus < 300 && jsonParsed && responseOk;
}

inline bool scanFeedbackUsesGreen(ScanFeedbackState state)
{
    return state == ScanFeedbackState::SERVER_CONFIRMED;
}

inline bool scanFeedbackUsesSuccessBeep(ScanFeedbackState state)
{
    return state == ScanFeedbackState::SERVER_CONFIRMED;
}

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

inline QueueFailureAction queueFailureActionForScanResponse(
    int status,
    bool jsonParsed,
    bool responseOk,
    const char *code
) {
    if (status >= 200 && status < 300
        && !serverResponseConfirmsBooking(status, jsonParsed, responseOk)) {
        return QueueFailureAction::DEAD_LETTER_RECORD;
    }
    return queueFailureActionFor(status, code);
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

inline bool terminalCalendarDateValid(int year, int month, int day)
{
    if (year < 2000 || year > 2099 || month < 1 || month > 12 || day < 1) return false;
    static const uint8_t daysPerMonth[] = {31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31};
    int maximumDay = daysPerMonth[month - 1];
    const bool leapYear = (year % 4 == 0 && year % 100 != 0) || year % 400 == 0;
    if (month == 2 && leapYear) maximumDay = 29;
    return day <= maximumDay;
}

inline bool formatQueuedRecordBerlinDate(const char *deviceTime, char *buffer, size_t bufferSize)
{
    if (deviceTime == nullptr || buffer == nullptr || bufferSize < 11) return false;

    int year = 0;
    int month = 0;
    int day = 0;
    int hour = 0;
    int minute = 0;
    int second = 0;
    char trailing = '\0';

    // Firmware 1.1.0 through 1.1.2 queue records may contain the former local
    // dd.mm.yyyy value. It already represents the Berlin calendar day.
    if (std::sscanf(deviceTime, "%2d.%2d.%4d %2d:%2d:%2d%c",
        &day, &month, &year, &hour, &minute, &second, &trailing) == 6) {
        if (!terminalCalendarDateValid(year, month, day)
            || hour < 0 || hour > 23 || minute < 0 || minute > 59 || second < 0 || second > 60) {
            return false;
        }
        return std::snprintf(buffer, bufferSize, "%02d.%02d.%04d", day, month, year) == 10;
    }

    // Current records use UTC ISO-8601. Convert them to Europe/Berlin before
    // comparing days so scans around UTC midnight are classified correctly.
    if (std::sscanf(deviceTime, "%4d-%2d-%2dT%2d:%2d:%2dZ%c",
        &year, &month, &day, &hour, &minute, &second, &trailing) != 6
        || !terminalCalendarDateValid(year, month, day)
        || hour < 0 || hour > 23 || minute < 0 || minute > 59 || second < 0 || second > 60) {
        return false;
    }

    struct tm utc = {};
    utc.tm_year = year - 1900;
    utc.tm_mon = month - 1;
    utc.tm_mday = day;
    utc.tm_hour = hour;
    utc.tm_min = minute;
    utc.tm_sec = second;
    const time_t epoch = terminalUtcTmToEpoch(utc);
    struct tm berlin = {};
    if (!terminalTimeValid(epoch) || localtime_r(&epoch, &berlin) == nullptr) return false;
    return std::strftime(buffer, bufferSize, "%d.%m.%Y", &berlin) == 10;
}

inline bool queuedRecordBelongsToCurrentBerlinDay(const char *deviceTime, const char *currentBerlinClock)
{
    if (currentBerlinClock == nullptr || std::strlen(currentBerlinClock) < 10) return false;
    char queuedDate[11] = {};
    if (!formatQueuedRecordBerlinDate(deviceTime, queuedDate, sizeof(queuedDate))) return false;
    return std::strncmp(queuedDate, currentBerlinClock, 10) == 0;
}

inline uint32_t terminalSecondsUntilNextDay(int hour, int minute, int second)
{
    if (hour < 0 || hour > 23 || minute < 0 || minute > 59 || second < 0 || second > 59) return 0;
    return 86400U - static_cast<uint32_t>(hour * 3600 + minute * 60 + second);
}

enum class QueueReplayDateDecision {
    ALLOW,
    DEFER_MIDNIGHT,
    REJECT_DATE
};

inline QueueReplayDateDecision queueReplayDateDecisionForAttempt(
    const char *deviceTime,
    const char *currentBerlinClock,
    uint32_t secondsUntilNextDay,
    uint32_t midnightSafetySeconds
) {
    if (!queuedRecordBelongsToCurrentBerlinDay(deviceTime, currentBerlinClock)) {
        return QueueReplayDateDecision::REJECT_DATE;
    }
    if (secondsUntilNextDay > 0 && secondsUntilNextDay <= midnightSafetySeconds) {
        return QueueReplayDateDecision::DEFER_MIDNIGHT;
    }
    return QueueReplayDateDecision::ALLOW;
}

inline uint32_t queueSyncStartupDelayMilliseconds(
    bool taskWatchdogReset,
    size_t activeQueueDepth,
    uint32_t recoveryPauseMs
) {
    return taskWatchdogReset && activeQueueDepth > 0 ? recoveryPauseMs : 0;
}

inline bool rejectedRecordIdentityMatches(
    uint32_t expectedSequence,
    const char *expectedRequestId,
    const char *expectedUid,
    const char *expectedDeviceTime,
    uint32_t actualSequence,
    const char *actualRequestId,
    const char *actualUid,
    const char *actualDeviceTime
) {
    return expectedSequence == actualSequence
        && expectedRequestId != nullptr && actualRequestId != nullptr
        && expectedUid != nullptr && actualUid != nullptr
        && expectedDeviceTime != nullptr && actualDeviceTime != nullptr
        && std::strcmp(expectedRequestId, actualRequestId) == 0
        && std::strcmp(expectedUid, actualUid) == 0
        && std::strcmp(expectedDeviceTime, actualDeviceTime) == 0;
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

inline bool readyClockCheckDue(uint32_t now, uint32_t lastCheck, uint32_t interval, bool force)
{
    return force || static_cast<uint32_t>(now - lastCheck) >= interval;
}
