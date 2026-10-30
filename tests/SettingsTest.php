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

use PHPUnit\Framework\TestCase;

/**
 * Tests the default values and the validation of the plugin settings
 */
class SettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        $settings = new \ilIssueAnalysisSettings();

        $this->assertSame(30, $settings->getRetentionDays());
        $this->assertSame(10000, $settings->getMaxRecords());
        $this->assertTrue($settings->getMaskSensitive());
        $this->assertSame(120, $settings->getImportTimeLimit());
        $this->assertSame(1000, $settings->getImportLineLimit());
    }

    public function testMaskSensitiveCanBeDisabled(): void
    {
        $settings = new \ilIssueAnalysisSettings();
        $settings->setMaskSensitive(false);

        $this->assertFalse($settings->getMaskSensitive());
    }

    public function testValidSettingsHaveNoErrors(): void
    {
        $settings = new \ilIssueAnalysisSettings();

        $this->assertSame([], $settings->validateSettings([
            'retention_days' => 30,
            'max_records' => 10000,
            'import_time_limit' => 120,
            'import_line_limit' => 1000
        ]));
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'retention too low' => ['retention_days', 0, 'validation_retention_days'],
            'retention too high' => ['retention_days', 366, 'validation_retention_days'],
            'max records too low' => ['max_records', 99, 'validation_max_records'],
            'max records too high' => ['max_records', 100001, 'validation_max_records'],
            'time limit too low' => ['import_time_limit', 29, 'validation_time_limit'],
            'time limit too high' => ['import_time_limit', 601, 'validation_time_limit'],
            'line limit too low' => ['import_line_limit', 99, 'validation_line_limit'],
            'line limit too high' => ['import_line_limit', 10001, 'validation_line_limit'],
        ];
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testInvalidValueIsReported(string $key, int $value, string $error): void
    {
        $settings = new \ilIssueAnalysisSettings();

        $this->assertSame([$error], $settings->validateSettings([$key => $value]));
    }
}
