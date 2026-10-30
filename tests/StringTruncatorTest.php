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

use ILIAS\Plugin\xial\Service\StringTruncator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the multibyte-safe truncation of values for database columns
 */
class StringTruncatorTest extends TestCase
{
    public function testNullStaysNull(): void
    {
        $this->assertNull(StringTruncator::truncate(null, 10));
        $this->assertNull(StringTruncator::truncateFilePath(null, 10));
    }

    public function testShortValueIsUnchanged(): void
    {
        $this->assertSame('short', StringTruncator::truncate('short', 10));
    }

    public function testLongValueGetsSuffixWithinLimit(): void
    {
        $result = StringTruncator::truncate('abcdefghijklmnop', 10);

        $this->assertSame('abcdefg...', $result);
        $this->assertSame(10, mb_strlen($result));
    }

    public function testMultibyteCharactersAreNotSplit(): void
    {
        $result = StringTruncator::truncate('äöüäöüäöüäöü', 8);

        $this->assertSame('äöüäö...', $result);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    public function testSuffixLongerThanLimitIsOmitted(): void
    {
        $this->assertSame('ab', StringTruncator::truncate('abcdef', 2, '...'));
    }

    public function testFilePathKeepsTheEnd(): void
    {
        $result = StringTruncator::truncateFilePath('/var/www/html/ilias/Services/Foo/classes/class.ilFoo.php', 20);

        $this->assertSame('...s/class.ilFoo.php', $result);
        $this->assertSame(20, mb_strlen($result));
    }

    public function testTruncateFieldsOnlyTouchesConfiguredStrings(): void
    {
        $data = ['message' => str_repeat('x', 20), 'line' => 12, 'file' => 'a.php'];

        $result = StringTruncator::truncateFields($data, ['message' => 10, 'line' => 1, 'missing' => 5]);

        $this->assertSame('xxxxxxx...', $result['message']);
        $this->assertSame(12, $result['line']);
        $this->assertSame('a.php', $result['file']);
        $this->assertArrayNotHasKey('missing', $result);
    }
}
