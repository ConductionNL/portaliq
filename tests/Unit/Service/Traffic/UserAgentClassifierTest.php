<?php

/**
 * Unit tests for UserAgentClassifier.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\Traffic\UserAgentClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Three families and a bot flag, from the strings browsers actually send.
 */
class UserAgentClassifierTest extends TestCase {

	/**
	 * Real user agents and the families they must reduce to.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function agents(): array {
		return [
			'Windows Chrome' => [
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
				'desktop', 'Chrome', 'Windows',
			],
			'Windows Edge' => [
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 Edg/128.0.0.0',
				'desktop', 'Edge', 'Windows',
			],
			'macOS Safari' => [
				'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
				'desktop', 'Safari', 'macOS',
			],
			'iPhone Safari' => [
				'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
				'mobile', 'Safari', 'iOS',
			],
			'iPad' => [
				'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
				'tablet', 'Safari', 'iOS',
			],
			'Android phone Chrome' => [
				'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36',
				'mobile', 'Chrome', 'Android',
			],
			'Android tablet' => [
				'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
				'tablet', 'Chrome', 'Android',
			],
			'Linux Firefox' => [
				'Mozilla/5.0 (X11; Linux x86_64; rv:129.0) Gecko/20100101 Firefox/129.0',
				'desktop', 'Firefox', 'Linux',
			],
			'empty' => ['', 'other', 'Other', 'Other'],
		];
	}//end agents()


	/**
	 * @dataProvider agents
	 *
	 * @param string $agent   The user agent.
	 * @param string $device  The expected device family.
	 * @param string $browser The expected browser family.
	 * @param string $os      The expected OS family.
	 *
	 * @return void
	 */
	public function testAgentsReduceToTheirFamilies(string $agent, string $device, string $browser, string $os): void {
		$result = (new UserAgentClassifier())->classify(userAgent: $agent);

		$this->assertSame($device, $result['deviceType']);
		$this->assertSame($browser, $result['browser']);
		$this->assertSame($os, $result['os']);
		$this->assertFalse($result['bot'], 'a real browser is not a bot');
	}//end testAgentsReduceToTheirFamilies()


	/**
	 * Crawlers, monitors and scripts are flagged; an EMPTY agent is not.
	 *
	 * A privacy proxy strips the header, and the visitor behind it is still a
	 * visitor. Flagging empty as bot would silently uncount exactly the
	 * privacy-conscious.
	 *
	 * @return void
	 */
	public function testBotsAreFlaggedAndAnEmptyAgentIsNot(): void {
		$classifier = new UserAgentClassifier();

		foreach ([
			'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
			'curl/8.5.0',
			'python-requests/2.32',
			'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/128.0.0.0 Safari/537.36',
			'Pingdom.com_bot_version_1.4',
		] as $agent) {
			$this->assertTrue($classifier->classify(userAgent: $agent)['bot'], $agent . ' must be a bot');
		}

		$this->assertFalse($classifier->classify(userAgent: '')['bot']);
	}//end testBotsAreFlaggedAndAnEmptyAgentIsNot()


	/**
	 * No version number survives into any family.
	 *
	 * The families are what keep the stored dimension from being a
	 * fingerprint; a version string would put most of the entropy back.
	 *
	 * @return void
	 */
	public function testNoVersionNumberSurvives(): void {
		$result = (new UserAgentClassifier())->classify(
			userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.6613.84 Safari/537.36'
		);

		foreach (['deviceType', 'browser', 'os'] as $key) {
			$this->assertDoesNotMatchRegularExpression('/\d/', $result[$key], $key . ' must carry no version digits');
		}
	}//end testNoVersionNumberSurvives()


}//end class
