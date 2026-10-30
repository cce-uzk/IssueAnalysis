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
 * Tests the masking of sensitive data in error logs
 */
class SanitizerTest extends TestCase
{
    private const ENVIRONMENT = [
        'host' => 'ilias.example.org',
        'ilias_root' => '/srv/www/ilias',
        'document_root' => '/srv/www',
        'client_id' => 'myclient',
        'session_prefix' => ''
    ];

    private function sanitizer(bool $mask = true, array $environment = self::ENVIRONMENT): \ilIssueAnalysisSanitizer
    {
        return new \ilIssueAnalysisSanitizer($mask, $environment);
    }

    public function testSensitiveRequestValuesAreMasked(): void
    {
        $result = $this->sanitizer()->sanitizeRequestData([
            'password' => 'supersecret',
            'auth_token' => 'abcdef123456',
            'nested' => ['api_key' => 'k3y-value'],
            'cmd' => 'showList',
            'ref_id' => 42
        ]);

        $this->assertSame('sup********', $result['password']);
        $this->assertSame('abc*********', $result['auth_token']);
        $this->assertSame('k3*******', $result['nested']['api_key']);
        $this->assertSame('showList', $result['cmd']);
        $this->assertSame(42, $result['ref_id']);
    }

    public function testShortSensitiveValuesAreMaskedCompletely(): void
    {
        $result = $this->sanitizer()->sanitizeRequestData(['pwd' => 'ab']);

        $this->assertSame('**', $result['pwd']);
    }

    public function testRequestDataIsUnchangedWithoutMasking(): void
    {
        $data = ['password' => 'supersecret'];

        $this->assertSame($data, $this->sanitizer(false)->sanitizeRequestData($data));
    }

    public function testUserAgentIsAlwaysHashed(): void
    {
        $expected = hash('sha256', 'Mozilla/5.0');

        $this->assertSame($expected, $this->sanitizer()->sanitizeUserAgent('Mozilla/5.0'));
        $this->assertSame($expected, $this->sanitizer(false)->sanitizeUserAgent('Mozilla/5.0'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function ipProvider(): array
    {
        return [
            'IPv4' => ['134.95.12.34', '134.95.12.***'],
            'IPv6' => ['2001:db8:85a3:8d3:1319:8a2e:370:7348', '2001:db8:85a3:8d3:***:***:***:***'],
        ];
    }

    /**
     * @dataProvider ipProvider
     */
    public function testIpAddressIsShortened(string $ip, string $expected): void
    {
        $this->assertSame($expected, $this->sanitizer()->sanitizeIpAddress($ip));
        $this->assertSame($ip, $this->sanitizer(false)->sanitizeIpAddress($ip));
    }

    public function testSessionIdKeepsOnlyFirstAndLastCharacters(): void
    {
        $this->assertSame('abcd********wxyz', $this->sanitizer()->sanitizeSessionId('abcdefghijklwxyz'));
        $this->assertSame('******', $this->sanitizer()->sanitizeSessionId('abcdef'));
        $this->assertNull($this->sanitizer()->sanitizeSessionId(null));
    }

    public function testSharingReplacesSystemValues(): void
    {
        $content = "Error in /srv/www/ilias/Services/Foo.php:12\n"
            . "Host: ilias.example.org, files in /opt/lms/data/myclient/mobs\n"
            . "Mail to jane.doe@example.org from 192.168.1.20\n"
            . "See https://ilias.example.org/goto.php?target=crs_1\n"
            . "Doc root /srv/www/data";

        $result = $this->sanitizer(false)->sanitizeForSharing($content);

        $this->assertStringContainsString('[ILIAS_ROOT]/Services/Foo.php:12', $result);
        $this->assertStringContainsString('/data/[CLIENT_ID]/mobs', $result);
        $this->assertStringContainsString('[EMAIL]', $result);
        $this->assertStringContainsString('[IP_ADDRESS]', $result);
        $this->assertStringContainsString('[URL]', $result);
        $this->assertStringContainsString('[SERVER_ROOT]/data', $result);
        $this->assertStringNotContainsString('ilias.example.org', $result);
        $this->assertStringNotContainsString('jane.doe', $result);
        $this->assertStringNotContainsString('192.168.1.20', $result);
    }

    public function testTextContentIsOnlySanitizedWithMasking(): void
    {
        $content = 'Error in /srv/www/ilias/index.php';

        $this->assertSame($content, $this->sanitizer(false)->sanitizeTextContent($content));
        $this->assertSame('Error in [ILIAS_ROOT]/index.php', $this->sanitizer()->sanitizeTextContent($content));
    }

    public function testWordsAreKeptWithoutSessionCookie(): void
    {
        $result = $this->sanitizer()->sanitizeForSharing('unknown column in field list');

        $this->assertSame('unknown column in field list', $result);
    }

    public function testSessionIdsWithPrefixOfCurrentSessionAreReplaced(): void
    {
        $environment = array_merge(self::ENVIRONMENT, ['session_prefix' => 'k3j9a']);

        $result = $this->sanitizer(true, $environment)->sanitizeForSharing('PHPSESSID=k3j9a8f7d6s5a4');

        $this->assertSame('PHPSESSID=[SESSION_ID]', $result);
    }

    public function testServerRootDoesNotReplaceEverySlash(): void
    {
        $environment = array_merge(self::ENVIRONMENT, ['ilias_root' => '/ilias', 'document_root' => '']);

        $result = $this->sanitizer(true, $environment)->sanitizeForSharing('/ilias/index.php in /opt/app');

        $this->assertSame('[ILIAS_ROOT]/index.php in /opt/app', $result);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function ipInTextProvider(): array
    {
        return [
            'public IPv4' => ['REMOTE_ADDR              : 89.1.213.158', 'REMOTE_ADDR              : [IP_ADDRESS]'],
            'server IPv4' => ['SERVER_ADDR : 134.95.112.130', 'SERVER_ADDR : [IP_ADDRESS]'],
            'forwarded list' => ['X_FORWARDED_FOR: 89.1.213.158, 10.0.0.1', 'X_FORWARDED_FOR: [IP_ADDRESS], [IP_ADDRESS]'],
            'IPv6 full' => ['from 2001:0db8:85a3:0000:0000:8a2e:0370:7334 end', 'from [IP_ADDRESS] end'],
            'IPv6 compressed' => ['from 2001:db8::1 end', 'from [IP_ADDRESS] end'],
            'IPv6 loopback' => ['REMOTE_ADDR : ::1', 'REMOTE_ADDR : [IP_ADDRESS]'],
        ];
    }

    /**
     * @dataProvider ipInTextProvider
     */
    public function testAllIpAddressesAreMaskedForSharing(string $content, string $expected): void
    {
        $this->assertSame($expected, $this->sanitizer(false)->sanitizeForSharing($content));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noIpProvider(): array
    {
        return [
            'browser version' => ['Chrome/143.0.0.0 Safari/537.36'],
            'invalid octet' => ['value 999.1.1.1'],
            'time' => ['02-May-2024 07:51:00 UTC'],
            'static call' => ['ilObjRootFolderGUI::framesetObject()'],
            'version with four parts' => ['version 9.20.0.1a'],
        ];
    }

    /**
     * @dataProvider noIpProvider
     */
    public function testOtherNumbersAreKept(string $content): void
    {
        $this->assertSame($content, $this->sanitizer(false)->sanitizeForSharing($content));
    }

    private const NO_ENVIRONMENT = [
        'host' => '',
        'ilias_root' => '',
        'document_root' => '',
        'client_id' => '',
        'session_prefix' => ''
    ];

    private function share(string $content): string
    {
        return $this->sanitizer(false, self::NO_ENVIRONMENT)->sanitizeForSharing($content);
    }

    public function testHostNamesOfTheErrorFileAreMaskedEverywhere(): void
    {
        $result = $this->share(
            "HTTP_HOST                : www.ilias.uni-koeln.de\n"
            . "SERVER_NAME              : www.ilias.uni-koeln.de\n"
            . "DOCUMENT_ROOT            : /srv/www/www.ilias.uni-koeln.de\n"
            . "Cookie domain ilias.uni-koeln.de"
        );

        $this->assertStringNotContainsString('uni-koeln', $result);
        $this->assertStringContainsString('HTTP_HOST                : [HOSTNAME]', $result);
        $this->assertStringContainsString('DOCUMENT_ROOT            : /srv/www/[HOSTNAME]', $result);
        $this->assertStringContainsString('Cookie domain [HOSTNAME]', $result);
    }

    public function testHostNamesFromUrlsAreMasked(): void
    {
        $result = $this->share("HTTP_REFERER : https://lms.example.org:8443/goto.php\nsee lms.example.org");

        $this->assertSame("HTTP_REFERER : [URL]\nsee [HOSTNAME]", $result);
    }

    public function testClientIdIsMaskedInCookiesUrlsAndPaths(): void
    {
        $result = $this->share(
            "[ilClientId] => uk\n"
            . "HTTP_X_TEST : ilClientId=uk; other=1\n"
            . "goto.php?target=crs_1&client_id=uk\n"
            . "/opt/lms/data/uk/mobs/mm_1/file.jpg"
        );

        $this->assertStringContainsString('[ilClientId] => [CLIENT_ID]', $result);
        $this->assertStringContainsString('ilClientId=[CLIENT_ID]; other=1', $result);
        $this->assertStringContainsString('&client_id=[CLIENT_ID]', $result);
        $this->assertStringContainsString('/data/[CLIENT_ID]/mobs/', $result);
    }

    public function testShortClientIdDoesNotChangeOrdinaryWords(): void
    {
        $result = $this->share("[ilClientId] => uk\nDruck auf die Zukunft");

        $this->assertStringContainsString('Druck auf die Zukunft', $result);
    }

    public function testSessionIdsAreMaskedByKey(): void
    {
        $result = $this->share(
            "[PHPSESSID] => e749e (SHORTENED FOR SECURITY)\n"
            . "X_HEADER : PHPSESSID=abc123def; lang=de"
        );

        $this->assertStringContainsString('[PHPSESSID] => [SESSION_ID]', $result);
        $this->assertStringContainsString('PHPSESSID=[SESSION_ID]; lang=de', $result);
        $this->assertStringNotContainsString('e749e', $result);
    }

    public function testCookieLinesAreMaskedCompletely(): void
    {
        $result = $this->share('HTTP_COOKIE              : PHPSESSID=e749e; ilClientId=uk');

        $this->assertSame('HTTP_COOKIE              : [MASKED]', $result);
    }

    public function testValuesOfSensitiveKeysAreMaskedInTextLines(): void
    {
        $result = $this->share(
            "_authsession_user_id     : 1813973\n"
            . "rtoken                   : O:11:\"ilCtrlToken\":1:{s:64:\"110eeae1\";}\n"
            . "                             [password] => geheim\n"
            . "tbl_limit                : 20\n"
            . "                             [cmd] => showList"
        );

        $this->assertStringContainsString('_authsession_user_id     : [MASKED]', $result);
        $this->assertStringContainsString('rtoken                   : [MASKED]', $result);
        $this->assertStringContainsString('[password] => [MASKED]', $result);
        $this->assertStringContainsString('tbl_limit                : 20', $result);
        $this->assertStringContainsString('[cmd] => showList', $result);
        $this->assertStringNotContainsString('1813973', $result);
        $this->assertStringNotContainsString('geheim', $result);
    }
}
