<?php

/**
 * Portaliq Traffic Referrer Classifier.
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
 * Derives where a visit came from: the referrer's host, the acquisition
 * channel, the campaign parameters in the page URL, and the page path with
 * those parameters stripped.
 *
 * The channel vocabulary is GA4's, so a number here means what the same
 * number means there: direct, organic search, social, referral, email,
 * campaign, and internal for a move within the same site.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-derived-dimensions-must-be-computed-on-the-server-and-never-accepted-from-the-client
 */
class ReferrerClassifier {

	/**
	 * Query keys carrying campaign attribution, GA (utm_) and Matomo (mtm_)
	 * spellings, mapped onto the stored dimension.
	 *
	 * @var array<string, string>
	 */
	private const CAMPAIGN_KEYS = [
		'utm_campaign' => 'campaign',
		'mtm_campaign' => 'campaign',
		'utm_source' => 'source',
		'mtm_source' => 'source',
		'utm_medium' => 'medium',
		'mtm_medium' => 'medium',
		'utm_content' => 'content',
		'mtm_content' => 'content',
		'utm_term' => 'term',
		'mtm_kwd' => 'term',
	];

	/**
	 * Hosts (suffix match) that are search engines.
	 *
	 * @var string[]
	 */
	private const SEARCH_HOSTS = [
		'google.',
		'bing.com',
		'duckduckgo.com',
		'yahoo.',
		'ecosia.org',
		'startpage.com',
		'qwant.com',
		'yandex.',
		'baidu.com',
		'search.brave.com',
	];

	/**
	 * Hosts (suffix match) that are social networks.
	 *
	 * @var string[]
	 */
	private const SOCIAL_HOSTS = [
		'facebook.com',
		'instagram.com',
		'linkedin.com',
		'twitter.com',
		'x.com',
		't.co',
		'youtube.com',
		'tiktok.com',
		'reddit.com',
		'whatsapp.com',
		'telegram.org',
		'threads.net',
		'mastodon.',
		'pinterest.',
	];

	/**
	 * The referrer's hostname, lower-cased, or '' when there is none.
	 *
	 * @param string $referrer The page referrer.
	 *
	 * @return string The host.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-derived-dimensions-must-be-computed-on-the-server-and-never-accepted-from-the-client
	 */
	public function host(string $referrer): string {
		$host = parse_url(trim($referrer), PHP_URL_HOST);
		if (is_string($host) === false) {
			return '';
		}

		return strtolower($host);
	}

	/**
	 * The campaign fields carried by a page URL's query string.
	 *
	 * @param string $location The page location.
	 *
	 * @return array<string, string> Any of campaign, source, medium, content, term.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-derived-dimensions-must-be-computed-on-the-server-and-never-accepted-from-the-client
	 */
	public function campaign(string $location): array {
		$query = parse_url(trim($location), PHP_URL_QUERY);
		if (is_string($query) === false || $query === '') {
			return [];
		}

		parse_str($query, $params);

		$out = [];
		foreach (self::CAMPAIGN_KEYS as $key => $dimension) {
			$value = $params[$key] ?? null;
			if (is_string($value) === false || trim($value) === '' || isset($out[$dimension]) === true) {
				continue;
			}

			$out[$dimension] = mb_substr(trim($value), 0, 128);
		}

		return $out;
	}

	/**
	 * The path of a page location with its query string removed.
	 *
	 * The WHOLE query goes, not only the campaign keys. A query string on a
	 * government portal carries search terms and case numbers, and the
	 * page-level aggregate must not become a second place they are stored.
	 * The full location is kept on the event itself, bounded, under the
	 * portal's retention.
	 *
	 * A location that is not an http(s) URL (a `mailto:blast/...` marker from
	 * the mail integration) is kept as it is.
	 *
	 * @param string $location The page location.
	 *
	 * @return string The path, or the location itself when it has no path.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-derived-dimensions-must-be-computed-on-the-server-and-never-accepted-from-the-client
	 */
	public function path(string $location): string {
		$location = trim($location);
		$scheme = parse_url($location, PHP_URL_SCHEME);
		if (in_array($scheme, ['http', 'https'], true) === false) {
			return mb_substr($location, 0, 512);
		}

		$path = parse_url($location, PHP_URL_PATH);
		if (is_string($path) === false || $path === '') {
			return '/';
		}

		return mb_substr($path, 0, 512);
	}

	/**
	 * The acquisition channel for a page view.
	 *
	 * @param string                $referrerHost The referrer's host, or ''.
	 * @param string                $pageHost     The page's own host, or ''.
	 * @param array<string, string> $campaign     The campaign fields, if any.
	 *
	 * @return string One of the channel names.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-derived-dimensions-must-be-computed-on-the-server-and-never-accepted-from-the-client
	 */
	public function channel(string $referrerHost, string $pageHost, array $campaign): string {
		$medium = strtolower($campaign['medium'] ?? '');
		$source = strtolower($campaign['source'] ?? '');
		if (in_array($medium, ['email', 'e-mail', 'newsletter'], true) === true || $source === 'email') {
			return 'email';
		}

		if ($campaign !== []) {
			return 'campaign';
		}

		if ($referrerHost === '') {
			return 'direct';
		}

		if ($pageHost !== '' && $referrerHost === $pageHost) {
			return 'internal';
		}

		if ($this->matches(host: $referrerHost, suffixes: self::SEARCH_HOSTS) === true) {
			return 'organic search';
		}

		if ($this->matches(host: $referrerHost, suffixes: self::SOCIAL_HOSTS) === true) {
			return 'social';
		}

		return 'referral';
	}

	/**
	 * Whether a host is, or is a subdomain of, one of the suffixes.
	 *
	 * A suffix ending in a dot (`google.`) matches any TLD after it.
	 *
	 * @param string   $host     The lower-cased host.
	 * @param string[] $suffixes The suffixes.
	 *
	 * @return bool True on a match.
	 */
	private function matches(string $host, array $suffixes): bool {
		foreach ($suffixes as $suffix) {
			if (str_ends_with($suffix, '.') === true) {
				if ($host === rtrim($suffix, '.') || str_contains($host, '.' . $suffix) === true || str_starts_with($host, $suffix) === true) {
					return true;
				}

				continue;
			}

			if ($host === $suffix || str_ends_with($host, '.' . $suffix) === true) {
				return true;
			}
		}

		return false;
	}
}
