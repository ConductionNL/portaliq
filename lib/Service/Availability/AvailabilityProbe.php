<?php

/**
 * Portaliq availability probe
 *
 * Checks one portal the way a visitor reaches it: its public site route and
 * the health check, through the instance's own URL.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Availability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-each-published-portal-is-checked-every-five-minutes-req-oar-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Availability;

use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use Throwable;

/**
 * One check: available when the site route answers 200 and health says ok,
 * degraded when the site answers but health does not say ok, down when the
 * site does not answer 200 within five seconds.
 *
 * The request passes through the web server and PHP like a visitor's, and
 * through nothing in front of the installation. The report says so.
 *
 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-each-published-portal-is-checked-every-five-minutes-req-oar-001
 */
class AvailabilityProbe {
	/**
	 * Seconds a visitor would wait before giving up.
	 */
	private const TIMEOUT = 5;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clients The HTTP client factory.
	 * @param IURLGenerator $urls Builds the instance's own absolute URLs.
	 */
	public function __construct(
		private readonly IClientService $clients,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * Check one portal.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array{status: string, cause: string}
	 *
	 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-each-published-portal-is-checked-every-five-minutes-req-oar-001
	 */
	public function check(string $slug): array {
		$site = $this->get(url: $this->urls->linkToRouteAbsolute('portaliq.content.site', ['portal' => $slug]));
		if ($site['status'] !== 200) {
			return ['status' => AvailabilityRollup::DOWN, 'cause' => $site['cause']];
		}

		$health = $this->get(url: $this->urls->linkToRouteAbsolute('portaliq.health.index'));
		$said = '';
		if ($health['body'] !== '') {
			$decoded = json_decode($health['body'], true);
			$said = (string)(is_array($decoded) === true ? ($decoded['status'] ?? '') : '');
		}

		if ($said === 'ok') {
			return ['status' => AvailabilityRollup::AVAILABLE, 'cause' => ''];
		}

		return ['status' => AvailabilityRollup::DEGRADED, 'cause' => 'health-degraded'];
	}//end check()

	/**
	 * One GET, never throwing.
	 *
	 * @param string $url The absolute URL.
	 *
	 * @return array{status: int, body: string, cause: string} Status 0 when nothing answered.
	 */
	private function get(string $url): array {
		try {
			$response = $this->clients->newClient()->get(
				$url,
				[
					'timeout' => self::TIMEOUT,
					'connect_timeout' => self::TIMEOUT,
					'http_errors' => false,
					// The instance calls its own, possibly private, address.
					'nextcloud' => ['allow_local_address' => true],
				]
			);
		} catch (Throwable $failure) {
			$message = strtolower($failure->getMessage());
			$cause = 'site-error';
			if (str_contains($message, 'timed out') === true || str_contains($message, 'timeout') === true) {
				$cause = 'timeout';
			}

			return ['status' => 0, 'body' => '', 'cause' => $cause];
		}

		$body = $response->getBody();

		return ['status' => $response->getStatusCode(), 'body' => (is_string($body) === true ? $body : ''), 'cause' => 'site-error'];
	}//end get()
}//end class
