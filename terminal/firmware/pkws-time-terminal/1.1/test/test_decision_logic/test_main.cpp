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

void testScanSendDelayAndMillisOverflow()
{
    TEST_ASSERT_FALSE(scanSendDue(100, 200));
    TEST_ASSERT_TRUE(scanSendDue(200, 200));
    TEST_ASSERT_TRUE(scanSendDue(201, 200));
    TEST_ASSERT_FALSE(scanSendDue(UINT32_MAX - 25, 50));
    TEST_ASSERT_FALSE(scanSendDue(49, 50));
    TEST_ASSERT_TRUE(scanSendDue(50, 50));
}

void testResultHoldDeadlineHandlesMillisOverflow()
{
    TEST_ASSERT_FALSE(terminalDeadlineReached(UINT32_MAX - 25, 50));
    TEST_ASSERT_FALSE(terminalDeadlineReached(49, 50));
    TEST_ASSERT_TRUE(terminalDeadlineReached(50, 50));
}

void testPendingAndScheduledDeadlinesHandleMillisOverflow()
{
    TEST_ASSERT_TRUE(terminalDeadlinePending(UINT32_MAX - 25, 50));
    TEST_ASSERT_TRUE(terminalDeadlinePending(49, 50));
    TEST_ASSERT_FALSE(terminalDeadlinePending(50, 50));
    TEST_ASSERT_TRUE(terminalScheduledDeadlineReached(123, 0));
    TEST_ASSERT_FALSE(terminalScheduledDeadlineReached(UINT32_MAX - 25, 50));
    TEST_ASSERT_TRUE(terminalScheduledDeadlineReached(50, 50));
    TEST_ASSERT_TRUE(terminalDeadlineReached(100, UINT32_MAX - 100));
    TEST_ASSERT_EQUAL_UINT32(76, terminalMillisecondsUntil(UINT32_MAX - 25, 50));
    TEST_ASSERT_EQUAL_UINT32(1, terminalMillisecondsUntil(49, 50));
    TEST_ASSERT_EQUAL_UINT32(0, terminalMillisecondsUntil(50, 50));
}

void testQueueRetryStateSequenceHandlesRolloverAndResume()
{
    const uint32_t wrappedDeadline = 50;
    TEST_ASSERT_EQUAL_UINT32(76, queueRetryWaitMilliseconds(true, UINT32_MAX - 25, wrappedDeadline));
    TEST_ASSERT_EQUAL_UINT32(1, queueRetryWaitMilliseconds(true, 49, wrappedDeadline));
    TEST_ASSERT_EQUAL_UINT32(0, queueRetryWaitMilliseconds(true, 50, wrappedDeadline));
    TEST_ASSERT_EQUAL_UINT32(0, queueRetryWaitMilliseconds(false, 1, UINT32_MAX - 100));

    const uint32_t resumedAt = 75;
    const uint32_t resumedDeadline = queueRetryDeadlineOnSyncEntry(resumedAt);
    TEST_ASSERT_EQUAL_UINT32(resumedAt, resumedDeadline);
    TEST_ASSERT_EQUAL_UINT32(0, queueRetryWaitMilliseconds(true, resumedAt, resumedDeadline));
}

void testQueuedConfirmationRequiresLocalAck()
{
    TEST_ASSERT_TRUE(queuedConfirmationComplete(true, true));
    TEST_ASSERT_FALSE(queuedConfirmationComplete(true, false));
    TEST_ASSERT_FALSE(queuedConfirmationComplete(false, true));
}

void testQueueResponsesDoNotUseLiveScanFeedback()
{
    TEST_ASSERT_TRUE(scanResponseShouldUpdateLiveFeedback(false));
    TEST_ASSERT_FALSE(scanResponseShouldUpdateLiveFeedback(true));
}

void testLongLiveRetryMovesToBackgroundQueue()
{
    TEST_ASSERT_FALSE(liveScanRetryShouldQueue(15000, 15000));
    TEST_ASSERT_TRUE(liveScanRetryShouldQueue(15001, 15000));
    TEST_ASSERT_TRUE(liveScanRetryShouldQueue(900000, 15000));
}

void testOperationDurationWatchdogHandlesMillisRollover()
{
    TEST_ASSERT_FALSE(operationDurationExceeded(UINT32_MAX - 50, UINT32_MAX - 100, 100));
    TEST_ASSERT_TRUE(operationDurationExceeded(25, UINT32_MAX - 100, 100));
    TEST_ASSERT_TRUE(operationDurationExceeded(1000, 0, 1000));
}

void testQueueBackgroundDeadlineCanOnlyBeExtended()
{
    TEST_ASSERT_EQUAL_UINT32(900100, extendedScheduledDeadline(100, 900100, 20000));
    TEST_ASSERT_EQUAL_UINT32(1200100, extendedScheduledDeadline(100, 900100, 1200000));
    TEST_ASSERT_EQUAL_UINT32(20100, extendedScheduledDeadline(100, 0, 20000));
    TEST_ASSERT_EQUAL_UINT32(50, extendedScheduledDeadline(UINT32_MAX - 100, 50, 100));
    TEST_ASSERT_EQUAL_UINT32(99, extendedScheduledDeadline(UINT32_MAX - 100, 50, 200));
}

void testPersistentQueueNotBeforeSurvivesRestartClock()
{
    TEST_ASSERT_EQUAL_UINT32(900000, persistentNotBeforeDelayMilliseconds(1000, 1900, 3600000));
    TEST_ASSERT_EQUAL_UINT32(0, persistentNotBeforeDelayMilliseconds(1900, 1900, 3600000));
    TEST_ASSERT_EQUAL_UINT32(0, persistentNotBeforeDelayMilliseconds(1901, 1900, 3600000));
    TEST_ASSERT_EQUAL_UINT32(3600000, persistentNotBeforeDelayMilliseconds(1000, 10000, 3600000));
    TEST_ASSERT_FALSE(relativeQueueDelayNeedsStart(42, 42));
    TEST_ASSERT_TRUE(relativeQueueDelayNeedsStart(42, 0));
    TEST_ASSERT_TRUE(relativeQueueDelayNeedsStart(43, 42));
}

void testServerResponseConfirmationRequiresExplicitOk()
{
    TEST_ASSERT_TRUE(serverResponseConfirmsBooking(200, true, true));
    TEST_ASSERT_TRUE(serverResponseConfirmsBooking(201, true, true));
    TEST_ASSERT_FALSE(serverResponseConfirmsBooking(200, true, false));
    TEST_ASSERT_FALSE(serverResponseConfirmsBooking(200, false, false));
    TEST_ASSERT_FALSE(serverResponseConfirmsBooking(204, false, false));
    TEST_ASSERT_FALSE(serverResponseConfirmsBooking(400, true, true));
    TEST_ASSERT_FALSE(serverResponseConfirmsBooking(401, true, true));
    TEST_ASSERT_FALSE(serverResponseConfirmsBooking(422, true, true));
    TEST_ASSERT_FALSE(serverResponseConfirmsBooking(500, true, true));
    TEST_ASSERT_FALSE(serverResponseConfirmsBooking(-1, false, false));
}

void testUnconfirmed2xxQueuedResponseUsesDeadLetter()
{
    TEST_ASSERT_EQUAL_INT((int) QueueFailureAction::CONFIRMED,
        (int) queueFailureActionForScanResponse(201, true, true, ""));
    TEST_ASSERT_EQUAL_INT((int) QueueFailureAction::DEAD_LETTER_RECORD,
        (int) queueFailureActionForScanResponse(200, true, false, ""));
    TEST_ASSERT_EQUAL_INT((int) QueueFailureAction::DEAD_LETTER_RECORD,
        (int) queueFailureActionForScanResponse(204, false, false, ""));
    TEST_ASSERT_EQUAL_INT((int) QueueFailureAction::RETRY_TEMPORARY,
        (int) queueFailureActionForScanResponse(500, true, true, ""));
    TEST_ASSERT_EQUAL_INT((int) QueueFailureAction::BLOCK_GLOBAL_KEEP_ACTIVE,
        (int) queueFailureActionForScanResponse(401, true, true, ""));
}

void testOnlyConfirmedServerResponseAllowsGreenAndSuccessBeep()
{
    TEST_ASSERT_FALSE(scanFeedbackUsesGreen(ScanFeedbackState::WAITING_SERVER));
    TEST_ASSERT_FALSE(scanFeedbackUsesSuccessBeep(ScanFeedbackState::WAITING_SERVER));
    TEST_ASSERT_TRUE(scanFeedbackUsesGreen(ScanFeedbackState::SERVER_CONFIRMED));
    TEST_ASSERT_TRUE(scanFeedbackUsesSuccessBeep(ScanFeedbackState::SERVER_CONFIRMED));
    TEST_ASSERT_FALSE(scanFeedbackUsesGreen(ScanFeedbackState::SERVER_REJECTED));
    TEST_ASSERT_FALSE(scanFeedbackUsesSuccessBeep(ScanFeedbackState::SERVER_REJECTED));
    TEST_ASSERT_FALSE(scanFeedbackUsesGreen(ScanFeedbackState::STORED_OFFLINE));
    TEST_ASSERT_FALSE(scanFeedbackUsesSuccessBeep(ScanFeedbackState::STORED_OFFLINE));
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
    RUN_TEST(testScanSendDelayAndMillisOverflow);
    RUN_TEST(testResultHoldDeadlineHandlesMillisOverflow);
    RUN_TEST(testPendingAndScheduledDeadlinesHandleMillisOverflow);
    RUN_TEST(testQueueRetryStateSequenceHandlesRolloverAndResume);
    RUN_TEST(testQueuedConfirmationRequiresLocalAck);
    RUN_TEST(testQueueResponsesDoNotUseLiveScanFeedback);
    RUN_TEST(testLongLiveRetryMovesToBackgroundQueue);
    RUN_TEST(testOperationDurationWatchdogHandlesMillisRollover);
    RUN_TEST(testQueueBackgroundDeadlineCanOnlyBeExtended);
    RUN_TEST(testPersistentQueueNotBeforeSurvivesRestartClock);
    RUN_TEST(testServerResponseConfirmationRequiresExplicitOk);
    RUN_TEST(testUnconfirmed2xxQueuedResponseUsesDeadLetter);
    RUN_TEST(testOnlyConfirmedServerResponseAllowsGreenAndSuccessBeep);
    RUN_TEST(testClockUsesPlaceholderUntilTimeIsValid);
    RUN_TEST(testClockUsesBerlinWinterAndSummerTime);
    RUN_TEST(testDeviceTimeRemainsUtc);
    RUN_TEST(testUtcCalendarConversionIsIndependentOfBerlinTimezone);
    RUN_TEST(testReadyClockRefreshOnlyRendersChangesInAllowedIdleState);
    RUN_TEST(testReadyClockCheckIntervalAndMillisOverflow);
    return UNITY_END();
}
