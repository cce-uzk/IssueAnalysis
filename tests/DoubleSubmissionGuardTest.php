<?php

/**
 * This file is part of the IssueAnalysis plugin for ILIAS.
 *
 * Copyright (c) University of Cologne, CompetenceCenter E-Learning
 *
 * The plugin is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 */

declare(strict_types=1);

namespace ILIAS\Plugin\xial\Tests;

use ILIAS\Plugin\xial\Service\DoubleSubmissionGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests the one-time tokens and the cooldown of the double submission guard
 */
class DoubleSubmissionGuardTest extends TestCase
{
    protected function setUp(): void
    {
        \ilSession::reset();
    }

    public function testValidTokenIsAcceptedOnce(): void
    {
        $token = DoubleSubmissionGuard::generateToken('import');

        $this->assertTrue(DoubleSubmissionGuard::validateAndConsumeToken('import', $token));
        $this->assertFalse(DoubleSubmissionGuard::validateAndConsumeToken('import', $token));
    }

    public function testWrongTokenIsRejectedAndKeepsValidToken(): void
    {
        $token = DoubleSubmissionGuard::generateToken('import');

        $this->assertFalse(DoubleSubmissionGuard::validateAndConsumeToken('import', 'wrong'));
        $this->assertTrue(DoubleSubmissionGuard::validateAndConsumeToken('import', $token));
    }

    public function testTokenIsBoundToItsAction(): void
    {
        $token = DoubleSubmissionGuard::generateToken('import');

        $this->assertFalse(DoubleSubmissionGuard::validateAndConsumeToken('clearData', $token));
    }

    public function testNewTokenReplacesPreviousTokenOfSameAction(): void
    {
        $first = DoubleSubmissionGuard::generateToken('import');
        $second = DoubleSubmissionGuard::generateToken('import');

        $this->assertNotSame($first, $second);
        $this->assertFalse(DoubleSubmissionGuard::validateAndConsumeToken('import', $first));
        $this->assertTrue(DoubleSubmissionGuard::validateAndConsumeToken('import', $second));
    }

    public function testExpiredTokenIsRejected(): void
    {
        \ilSession::set('xial_submission_tokens', [
            'import' => ['token' => 'old', 'timestamp' => time() - 301]
        ]);

        $this->assertFalse(DoubleSubmissionGuard::validateAndConsumeToken('import', 'old'));
    }

    public function testCooldown(): void
    {
        $this->assertFalse(DoubleSubmissionGuard::isInCooldown('import', 5));

        DoubleSubmissionGuard::markExecuted('import');

        $this->assertTrue(DoubleSubmissionGuard::isInCooldown('import', 5));
        $this->assertFalse(DoubleSubmissionGuard::isInCooldown('import', 0));
        $this->assertFalse(DoubleSubmissionGuard::isInCooldown('clearData', 5));
    }
}
