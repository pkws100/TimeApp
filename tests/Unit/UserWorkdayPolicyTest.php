<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Users\UserWorkdayPolicy;
use PHPUnit\Framework\TestCase;

final class UserWorkdayPolicyTest extends TestCase
{
    private UserWorkdayPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new UserWorkdayPolicy();
    }

    public function testTuesdayThroughThursdayMaskIsRespectedExactly(): void
    {
        $user = ['workdays_mask' => '2,3,4'];

        self::assertFalse($this->policy->isScheduledWorkday($user, '2026-05-11'));
        self::assertTrue($this->policy->isScheduledWorkday($user, '2026-05-12'));
        self::assertTrue($this->policy->isScheduledWorkday($user, '2026-05-13'));
        self::assertTrue($this->policy->isScheduledWorkday($user, '2026-05-14'));
        self::assertFalse($this->policy->isScheduledWorkday($user, '2026-05-15'));
    }

    public function testWeekendMasksAreSupported(): void
    {
        self::assertTrue($this->policy->isScheduledWorkday(['workdays_mask' => '6'], '2026-05-16'));
        self::assertFalse($this->policy->isScheduledWorkday(['workdays_mask' => '6'], '2026-05-17'));
        self::assertTrue($this->policy->isScheduledWorkday(['workdays_mask' => '7'], '2026-05-17'));
        self::assertFalse($this->policy->isScheduledWorkday(['workdays_mask' => '7'], '2026-05-16'));
    }

    public function testAlternatingWeekdayMaskIsSupported(): void
    {
        $user = ['workdays_mask' => '1,3,5'];

        self::assertSame([1, 3, 5], $this->policy->scheduledWorkdays($user));
        self::assertTrue($this->policy->isScheduledWorkday($user, '2026-05-11'));
        self::assertFalse($this->policy->isScheduledWorkday($user, '2026-05-12'));
        self::assertTrue($this->policy->isScheduledWorkday($user, '2026-05-13'));
        self::assertFalse($this->policy->isScheduledWorkday($user, '2026-05-14'));
        self::assertTrue($this->policy->isScheduledWorkday($user, '2026-05-15'));
    }

    public function testDuplicateAndInvalidValuesAreIgnored(): void
    {
        self::assertSame([2, 3, 6], $this->policy->scheduledWorkdays([
            'workdays_mask' => '2,2,3,0,8,text,,6, 3',
        ]));
        self::assertSame([], $this->policy->scheduledWorkdays([
            'workdays_mask' => '0,8,text',
        ]));
        self::assertFalse($this->policy->isScheduledWorkday(
            ['workdays_mask' => '0,8,text'],
            '2026-05-11'
        ));
    }

    public function testMissingNullAndEmptyMasksUseMondayThroughFridayFallback(): void
    {
        foreach ([
            [],
            ['workdays_mask' => null],
            ['workdays_mask' => ''],
            ['workdays_mask' => '   '],
        ] as $user) {
            self::assertSame(UserWorkdayPolicy::DEFAULT_WORKDAYS, $this->policy->scheduledWorkdays($user));
            self::assertTrue($this->policy->isScheduledWorkday($user, '2026-05-11'));
            self::assertFalse($this->policy->isScheduledWorkday($user, '2026-05-16'));
        }
    }

    public function testInvalidDateIsNeverScheduled(): void
    {
        self::assertFalse($this->policy->isScheduledWorkday(['workdays_mask' => '1,2,3,4,5'], '2026-02-31'));
        self::assertFalse($this->policy->isScheduledWorkday(['workdays_mask' => '1,2,3,4,5'], 'next monday'));
    }
}
