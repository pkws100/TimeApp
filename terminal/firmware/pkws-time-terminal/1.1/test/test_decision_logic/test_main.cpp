#include <unity.h>

#include <cstdlib>
#include <ctime>

#include "TerminalDecisionLogic.h"

static const time_t WINTER_UTC = 1768480440; // 2026-01-15 12:34:00 UTC
static const time_t SUMMER_UTC = 1784205240; // 2026-07-16 12:34:00 UTC

void useBerlinTimezone()
{
    setenv("TZ", TERMINAL_BERLIN_POSIX_TZ, 1);
    tzset();
}

void testQueueFailureClassification()
{
    TEST_ASSERT_EQUAL_INT((int) QueueFailureAction::BLOCK_GLOBAL_KEEP_ACTIVE, (int) queueFailureActionFor(401, "terminal_auth_failed"));
    TEST_ASSERT_EQUAL_INT((int) QueueFailureAction::BLOCK_GLOBAL_KEEP_ACTIVE, (int) queueFailureActionFor(403, "terminal_disabled"));
    TEST_ASSERT_EQUAL_INT((int) QueueFailureAction::DEAD_LETTER_RECORD, (int) queueFailureActionFor(422, "nfc_tag_invalid"));
    TEST_ASSERT_EQUAL_INT((int) QueueFailureAction::RETRY_TEMPORARY, (int) queueFailureActionFor(425, ""));
    TEST_ASSERT_EQUAL_INT((int) QueueFailureAction::RETRY_TEMPORARY, (int) queueFailureActionFor(429, ""));
    TEST_ASSERT_EQUAL_INT((int) QueueFailureAction::RETRY_TEMPORARY, (int) queueFailureActionFor(500, ""));
    TEST_ASSERT_EQUAL_INT((int) QueueFailureAction::BLOCK_GLOBAL_KEEP_ACTIVE, (int) queueFailureActionFor(400, "unknown_error"));
    TEST_ASSERT_EQUAL_INT((int) QueueFailureAction::CONFIRMED, (int) queueFailureActionFor(200, ""));
}

void testTrustRecoveryDecisions()
{
    TEST_ASSERT_EQUAL_INT((int) TrustRecoveryAction::RESTORE_PREVIOUS, (int) trustRecoveryActionFor(true, true, true, true, true, false));
    TEST_ASSERT_EQUAL_INT((int) TrustRecoveryAction::USE_FACTORY, (int) trustRecoveryActionFor(true, true, true, true, false, false));
    TEST_ASSERT_EQUAL_INT((int) TrustRecoveryAction::RESTORE_OLD_PENDING, (int) trustRecoveryActionFor(true, true, true, true, false, true));
    TEST_ASSERT_EQUAL_INT((int) TrustRecoveryAction::RESTORE_OLD_PENDING, (int) trustRecoveryActionFor(true, true, true, true, true, true));
    TEST_ASSERT_EQUAL_INT((int) TrustRecoveryAction::KEEP_VALID_ACTIVE, (int) trustRecoveryActionFor(true, true, true, false, false, false));
    TEST_ASSERT_EQUAL_INT((int) TrustRecoveryAction::FAIL_SAFE_FACTORY, (int) trustRecoveryActionFor(true, false, true, true, true, true));
    TEST_ASSERT_EQUAL_INT((int) TrustRecoveryAction::KEEP_VALID_ACTIVE, (int) trustRecoveryActionFor(false, false, true, false, false, false));
    // An unrelated quarantine file never changes a valid active decision when no transaction marker exists.
    TEST_ASSERT_EQUAL_INT((int) TrustRecoveryAction::KEEP_VALID_ACTIVE, (int) trustRecoveryActionFor(false, false, true, false, true, false));
}

void testRetryAfterSeconds()
{
    TEST_ASSERT_EQUAL_UINT32(30000, retryAfterMilliseconds("30"));
    TEST_ASSERT_EQUAL_UINT32(1000, retryAfterMilliseconds("0"));
    TEST_ASSERT_EQUAL_UINT32(900000, retryAfterMilliseconds("9999"));
    TEST_ASSERT_EQUAL_UINT32(0, retryAfterMilliseconds("Wed, 21 Oct 2026 07:28:00 GMT"));
}

void testClockUsesPlaceholderUntilTimeIsValid()
{
    char line[24];
    TEST_ASSERT_FALSE(terminalTimeValid(TERMINAL_VALID_TIME_AFTER_EPOCH));
    TEST_ASSERT_TRUE(terminalTimeValid(TERMINAL_VALID_TIME_AFTER_EPOCH + 1));
    TEST_ASSERT_FALSE(formatTerminalBerlinClock(SUMMER_UTC, false, line, sizeof(line)));
    TEST_ASSERT_EQUAL_STRING("--.--.---- --:--", line);
}

void testClockUsesBerlinWinterAndSummerTime()
{
    useBerlinTimezone();
    char line[24];

    TEST_ASSERT_TRUE(formatTerminalBerlinClock(WINTER_UTC, true, line, sizeof(line)));
    TEST_ASSERT_EQUAL_STRING("15.01.2026 13:34", line);

    TEST_ASSERT_TRUE(formatTerminalBerlinClock(SUMMER_UTC, true, line, sizeof(line)));
    TEST_ASSERT_EQUAL_STRING("16.07.2026 14:34", line);
}

void testDeviceTimeRemainsUtc()
{
    useBerlinTimezone();
    char timestamp[32];

    TEST_ASSERT_TRUE(formatTerminalUtcTimestamp(SUMMER_UTC, true, timestamp, sizeof(timestamp)));
    TEST_ASSERT_EQUAL_STRING("2026-07-16T12:34:00Z", timestamp);
}

void testUtcCalendarConversionIsIndependentOfBerlinTimezone()
{
    useBerlinTimezone();
    struct tm utc = {};
    utc.tm_year = 2026 - 1900;
    utc.tm_mon = 6;
    utc.tm_mday = 16;
    utc.tm_hour = 12;
    utc.tm_min = 34;

    TEST_ASSERT_EQUAL_INT64(SUMMER_UTC, terminalUtcTmToEpoch(utc));
}

void testReadyClockRefreshOnlyRendersChangesInAllowedIdleState()
{
    TEST_ASSERT_FALSE(readyClockRefreshRequired(true, false, false, "16.07.2026 14:34", "16.07.2026 14:34"));
    TEST_ASSERT_TRUE(readyClockRefreshRequired(true, false, false, "16.07.2026 14:34", "16.07.2026 14:35"));
    TEST_ASSERT_TRUE(readyClockRefreshRequired(true, false, false, "16.07.2026 23:59", "17.07.2026 00:00"));
    TEST_ASSERT_TRUE(readyClockRefreshRequired(true, false, false, "--.--.---- --:--", "16.07.2026 14:35"));
    TEST_ASSERT_TRUE(readyClockRefreshRequired(true, false, false, "16.07.2026 14:35", "--.--.---- --:--"));
    TEST_ASSERT_FALSE(readyClockRefreshRequired(false, false, false, "16.07.2026 14:34", "16.07.2026 14:35"));
    TEST_ASSERT_FALSE(readyClockRefreshRequired(true, true, false, "16.07.2026 14:34", "16.07.2026 14:35"));
    TEST_ASSERT_FALSE(readyClockRefreshRequired(true, false, true, "16.07.2026 14:34", "16.07.2026 14:35"));
}

void testReadyClockCheckIntervalAndMillisOverflow()
{
    TEST_ASSERT_FALSE(readyClockCheckDue(1999, 1000, 1000, false));
    TEST_ASSERT_TRUE(readyClockCheckDue(2000, 1000, 1000, false));
    TEST_ASSERT_TRUE(readyClockCheckDue(1001, 1000, 1000, true));
    TEST_ASSERT_FALSE(readyClockCheckDue(498, UINT32_MAX - 500, 1000, false));
    TEST_ASSERT_TRUE(readyClockCheckDue(499, UINT32_MAX - 500, 1000, false));
}

int main(int, char **)
{
    UNITY_BEGIN();
    RUN_TEST(testQueueFailureClassification);
    RUN_TEST(testTrustRecoveryDecisions);
    RUN_TEST(testRetryAfterSeconds);
    RUN_TEST(testClockUsesPlaceholderUntilTimeIsValid);
    RUN_TEST(testClockUsesBerlinWinterAndSummerTime);
    RUN_TEST(testDeviceTimeRemainsUtc);
    RUN_TEST(testUtcCalendarConversionIsIndependentOfBerlinTimezone);
    RUN_TEST(testReadyClockRefreshOnlyRendersChangesInAllowedIdleState);
    RUN_TEST(testReadyClockCheckIntervalAndMillisOverflow);
    return UNITY_END();
}
