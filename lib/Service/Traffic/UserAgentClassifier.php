<?php

/**
 * Portaliq Traffic User Agent Classifier.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-derived-dimensions-must-be-computed-on-the-server-and-never-accepted-from-the-client
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * Reduces a user agent string to three coarse families and a bot flag.
 *
 * DELIBERATELY SMALL. A full parser library knows a thousand devices and
 * that is the problem: a user agent plus a screen size plus a language is
 * a fingerprint, and the raw string is exactly what this app promises never
 * to store. Three families with no version numbers answer the question a
 * communications officer asks ("do people read this on their phone") and
 * cannot answer the one they should not.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-derived-dimensions-must-be-computed-on-the-server-and-never-accepted-from-the-client
 */
class UserAgentClassifier {

	/**
	 * Substrings that mark a crawler, a monitor or a script. Case-insensitive.
	 *
	 * `HeadlessChrome` is on the list: it is Puppeteer and Playwright by
	 * default, which is a test or a scraper and never a citizen.
	 *
	 * @var string[]
	 */
	private const BOT_MARKERS = [
		'bot',
		'crawl',
		'spider',
		'slurp',
		'curl/',
		'wget/',
		'python-requests',
		'python-urllib',
		'go-http-client',
		'java/',
		'libwww',
		'httpclient',
		'lighthouse',
		'pingdom',
		'uptimerobot',
		'facebookexternalhit',
		'headlesschrome',
		'phantomjs',
		'scrapy',
	];

	/**
	 * Classify one user agent string.
	 *
	 * @param string $userAgent The raw header value, which the caller discards.
	 *
	 * @return array{deviceType: string, browser: string, os: string, bot: bool} The families.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-derived-dimensions-must-be-computed-on-the-server-and-never-accepted-from-the-client
	 */
	public function classify(string $userAgent): array {
		$agent = trim($userAgent);

		return [
			'deviceType' => $this->deviceType(agent: $agent),
			'browser' => $this->browser(agent: $agent),
			'os' => $this->operatingSystem(agent: $agent),
			'bot' => $this->isBot(agent: $agent),
		];
	}

	/**
	 * Whether the agent identifies itself as software rather than a person.
	 *
	 * An EMPTY user agent is not a bot. Privacy proxies strip the header, and
	 * a visitor behind one is still a visitor.
	 *
	 * @param string $agent The trimmed user agent.
	 *
	 * @return bool True for a crawler, monitor or script.
	 */
	private function isBot(string $agent): bool {
		if ($agent === '') {
			return false;
		}

		$lower = strtolower($agent);
		foreach (self::BOT_MARKERS as $marker) {
			if (str_contains($lower, $marker) === true) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Desktop, mobile, tablet or other.
	 *
	 * Tablets are tested first because an iPad and an Android tablet both
	 * carry markers the mobile test would also match.
	 *
	 * @param string $agent The trimmed user agent.
	 *
	 * @return string The device family.
	 */
	private function deviceType(string $agent): string {
		if ($agent === '') {
			return 'other';
		}

		if (preg_match('/iPad|Tablet|Kindle|Silk|PlayBook/i', $agent) === 1) {
			return 'tablet';
		}

		if (preg_match('/Android/i', $agent) === 1 && preg_match('/Mobile/i', $agent) !== 1) {
			return 'tablet';
		}

		if (preg_match('/Mobi|iPhone|iPod|Windows Phone|BlackBerry|Opera Mini/i', $agent) === 1) {
			return 'mobile';
		}

		return 'desktop';
	}

	/**
	 * The browser family, without a version.
	 *
	 * Order matters: every Chromium browser also says "Chrome", and every
	 * WebKit browser also says "Safari", so the more specific marker is
	 * tested first.
	 *
	 * @param string $agent The trimmed user agent.
	 *
	 * @return string The browser family, or Other.
	 */
	private function browser(string $agent): string {
		$families = [
			'Edge' => '/Edg(e|A|iOS)?\//',
			'Opera' => '/OPR\/|Opera/',
			'Samsung Internet' => '/SamsungBrowser/',
			'Firefox' => '/Firefox\/|FxiOS/',
			'Chrome' => '/Chrome\/|CriOS/',
			'Safari' => '/Safari\//',
			'Internet Explorer' => '/MSIE|Trident\//',
		];

		foreach ($families as $family => $pattern) {
			if (preg_match($pattern, $agent) === 1) {
				return $family;
			}
		}

		return 'Other';
	}

	/**
	 * The operating system family, without a version.
	 *
	 * @param string $agent The trimmed user agent.
	 *
	 * @return string The OS family, or Other.
	 */
	private function operatingSystem(string $agent): string {
		$families = [
			'iOS' => '/iPhone|iPad|iPod/',
			'Android' => '/Android/',
			'Windows' => '/Windows/',
			'ChromeOS' => '/CrOS/',
			'macOS' => '/Macintosh|Mac OS X/',
			'Linux' => '/Linux|X11/',
		];

		foreach ($families as $family => $pattern) {
			if (preg_match($pattern, $agent) === 1) {
				return $family;
			}
		}

		return 'Other';
	}
}
