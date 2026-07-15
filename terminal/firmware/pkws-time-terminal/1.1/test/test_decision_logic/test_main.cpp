#include <unity.h>

#include "TerminalDecisionLogic.h"

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

int main(int, char **)
{
    UNITY_BEGIN();
    RUN_TEST(testQueueFailureClassification);
    RUN_TEST(testTrustRecoveryDecisions);
    RUN_TEST(testRetryAfterSeconds);
    return UNITY_END();
}
