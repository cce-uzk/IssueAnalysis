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
 * Tests the parsing of ILIAS error files and the stacktrace hash used for deduplication
 */
class ErrorFileParserTest extends TestCase
{
    private const STACKTRACE = "Error thrown with message \"Call to undefined method ilObjRootFolderGUI::framesetObject()\"\n\n"
        . "Stacktrace:\n"
        . "#1 Error in /var/www/html/ilias/Services/UICore/classes/class.ilCtrl.php:396\n"
        . "#0 /var/www/html/ilias/ilias.php:24\n";

    /** @var string[] */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function importer(int $retention_days = 30): \ilIssueAnalysisImporter
    {
        $settings = new \ilIssueAnalysisSettings();
        $property = new \ReflectionProperty(\ilIssueAnalysisSettings::class, 'settings');
        $property->setAccessible(true);
        $property->getValue($settings)->set('retention_days', (string) $retention_days);

        $importer = (new \ReflectionClass(\ilIssueAnalysisImporter::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(\ilIssueAnalysisImporter::class, 'settings');
        $property->setAccessible(true);
        $property->setValue($importer, $settings);

        return $importer;
    }

    private function parse(string $content, int $mtime = 0, int $retention_days = 30): ?array
    {
        $file = tempnam(sys_get_temp_dir(), 'xial');
        $this->files[] = $file;
        file_put_contents($file, $content);
        touch($file, $mtime ?: time());

        $method = new \ReflectionMethod(\ilIssueAnalysisImporter::class, 'parseIliasErrorFile');
        $method->setAccessible(true);

        return $method->invoke($this->importer($retention_days), $content, '00037_8216', $file);
    }

    public function testErrorFileIsParsed(): void
    {
        $result = $this->parse(self::STACKTRACE . "\n-- GET Data --\ncmd => frameset\n");

        $this->assertSame('00037_8216', $result['code']);
        $this->assertSame('error', $result['severity']);
        $this->assertSame(
            'Error thrown with message "Call to undefined method ilObjRootFolderGUI::framesetObject()"',
            $result['message']
        );
        $this->assertSame('/var/www/html/ilias/Services/UICore/classes/class.ilCtrl.php', $result['file']);
        $this->assertSame(396, $result['line']);
    }

    public function testHashIgnoresRequestData(): void
    {
        $first = $this->parse(self::STACKTRACE . "\n-- GET Data --\nref_id => 1\n\n-- Cookies --\nPHPSESSID => abc\n");
        $second = $this->parse(self::STACKTRACE . "\n-- GET Data --\nref_id => 2\n\n-- Cookies --\nPHPSESSID => xyz\n");

        $this->assertSame($first['stacktrace_hash'], $second['stacktrace_hash']);
        $this->assertSame(hash('sha256', trim(self::STACKTRACE)), $first['stacktrace_hash']);
        $this->assertStringNotContainsString('-- GET Data --', $first['stacktrace_only']);
    }

    public function testDifferentStacktracesHaveDifferentHashes(): void
    {
        $first = $this->parse(self::STACKTRACE);
        $second = $this->parse(str_replace(':396', ':397', self::STACKTRACE));

        $this->assertNotSame($first['stacktrace_hash'], $second['stacktrace_hash']);
    }

    public function testEmptyFileIsSkipped(): void
    {
        $this->assertNull($this->parse(''));
    }

    public function testFileOlderThanRetentionIsSkipped(): void
    {
        $this->assertNull($this->parse(self::STACKTRACE, time() - 40 * 86400, 30));
        $this->assertNotNull($this->parse(self::STACKTRACE, time() - 20 * 86400, 30));
    }
}
