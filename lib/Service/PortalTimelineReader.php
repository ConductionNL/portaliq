<?php

/**
 * Portaliq Portal Timeline Reader
 *
 * Reads the history a contributing app declares for one of its objects. The
 * app names a method on its own provider in the collection's
 * `timeline.provider` (dossiq: `caseTimeline`), and this calls it with the
 * object id. The app decides what is public: portaliq hands the entries on as
 * they came and adds, drops and reorders nothing (portaliq#723).
 *
 * Calling it proves nothing about who may see the object. The caller does
 * that first, through the same scoped read as a single object.
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
 * @spec openspec/specs/portal-contribution-contract/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Contribution\TimelineProviderMethod;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Calls a contributing app's declared timeline method.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md
 */
class PortalTimelineReader {
	/**
	 * Constructor.
	 *
	 * @param PortalProviderLocator $locator Finds the app's provider.
	 * @param LoggerInterface $logger Records a provider that failed.
	 */
	public function __construct(
		private readonly PortalProviderLocator $locator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The entries the app's provider returns for one object, or null.
	 *
	 * @param string $appId The contributing app.
	 * @param string $method The declared `timeline.provider`.
	 * @param string $id The object id, already proven to be the subject's.
	 *
	 * @return array<int, mixed>|null The entries as the provider returned
	 *                                them, or null when there is no callable
	 *                                method or the call failed.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md
	 */
	public function entries(string $appId, string $method, string $id): ?array {
		$provider = $this->locator->locate(appId: $appId);
		if ($provider === null || (new TimelineProviderMethod())->callableOn(provider: $provider, method: $method) === false) {
			return null;
		}

		try {
			$entries = $provider->{$method}($id);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: timeline provider failed', ['app' => $appId, 'method' => $method, 'reason' => $e->getMessage()]);
			return null;
		}

		if (is_array($entries) === false) {
			return null;
		}

		return array_values($entries);
	}//end entries()
}//end class
