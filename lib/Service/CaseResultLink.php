<?php

/**
 * Portaliq Case Result Link
 *
 * Keeps a case's `resultLink` only when it points where the instance itself
 * publishes: an https url on one of Nextcloud's `trusted_domains`
 * (woo-dossier-in-my-cases REQ-WDM-003). Any other link is dropped before the
 * case reaches the resident, and a warning is logged.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-dossier-in-my-cases/specs/citizen-writes-on-their-own-case/spec.md#requirement-the-case-links-to-where-its-result-is-public-req-wdm-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Filters a case's result link against the instance's trusted domains.
 *
 * @spec openspec/changes/woo-dossier-in-my-cases/specs/citizen-writes-on-their-own-case/spec.md#requirement-the-case-links-to-where-its-result-is-public-req-wdm-003
 */
class CaseResultLink {

	/**
	 * The case field that carries the link.
	 */
	public const FIELD = 'resultLink';

	/**
	 * The longest label kept.
	 */
	private const MAX_LABEL = 80;

	/**
	 * Constructor.
	 *
	 * @param IConfig         $config The system config, for `trusted_domains`.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The case with its result link as `{label, url}` when it may be shown,
	 * else without one. A case without a link is returned as it came.
	 *
	 * @param array<string, mixed> $case The case as read.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/woo-dossier-in-my-cases/specs/citizen-writes-on-their-own-case/spec.md#requirement-the-case-links-to-where-its-result-is-public-req-wdm-003
	 */
	public function kept(array $case): array {
		if (in_array(($case[self::FIELD] ?? null), [null, [], ''], true) === true) {
			unset($case[self::FIELD]);
			return $case;
		}

		$link = $case[self::FIELD];
		$url  = '';
		if (is_array($link) === true && is_string($link['url'] ?? null) === true) {
			$url = trim($link['url']);
		}

		if ($this->trusted(url: $url) === false) {
			unset($case[self::FIELD]);
			$this->logger->warning(
				'Portaliq: a case result link is not https on a trusted domain and is not shown',
				['case' => (string)($case['id'] ?? ($case['uuid'] ?? '')), 'host' => (string)(parse_url($url, PHP_URL_HOST) ?? '')]
			);
			return $case;
		}

		$label = '';
		if (is_string($link['label'] ?? null) === true) {
			$label = mb_substr(trim($link['label']), 0, self::MAX_LABEL);
		}

		$case[self::FIELD] = ['label' => $label, 'url' => $url];
		return $case;
	}//end kept()

	/**
	 * Whether a url is https on one of the instance's trusted domains.
	 *
	 * @param string $url The url.
	 *
	 * @return bool
	 */
	private function trusted(string $url): bool {
		$parts = parse_url($url);
		if (is_array($parts) === false || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
			return false;
		}

		$host = strtolower((string)($parts['host'] ?? ''));
		if ($host === '' || isset($parts['user']) === true || isset($parts['pass']) === true) {
			return false;
		}

		$withPort = $host;
		if (isset($parts['port']) === true) {
			$withPort = $host . ':' . (string)$parts['port'];
		}

		foreach ((array)$this->config->getSystemValue('trusted_domains', []) as $domain) {
			if ($this->matches(domain: strtolower(trim((string)$domain)), host: $host, withPort: $withPort) === true) {
				return true;
			}
		}

		return false;
	}//end trusted()

	/**
	 * Whether one trusted domain entry covers the host: exactly, with its
	 * port, or as a `*.` wildcard over a subdomain.
	 *
	 * @param string $domain   The trusted domain entry.
	 * @param string $host     The url's host.
	 * @param string $withPort The url's host and port.
	 *
	 * @return bool
	 */
	private function matches(string $domain, string $host, string $withPort): bool {
		if ($domain === '') {
			return false;
		}

		if ($domain === $withPort || ($domain === $host && $withPort === $host)) {
			return true;
		}

		if (str_starts_with($domain, '*.') === true) {
			return str_ends_with($host, substr($domain, 1)) === true && $host !== substr($domain, 2);
		}

		return false;
	}//end matches()
}//end class
