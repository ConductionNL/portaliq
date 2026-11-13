<?php

/**
 * Portaliq Contribution Census
 *
 * Which enabled apps contribute to the portal, for which audiences, and how
 * much each contribution declares. The portal only shows what sibling apps
 * contribute, and an app without a provider contributes nothing without a
 * word; this census makes that visible on an installation.
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
 * @spec openspec/changes/contributing-apps-census/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\Portaliq\Kernel\Contracts\ProviderAudiencesInterface;

/**
 * One row per enabled app: its provider, its audiences and what it declares.
 *
 * @spec openspec/changes/contributing-apps-census/tasks.md#T01
 */
class ContributionCensus {
	/**
	 * A row for an app whose provider was found and answered.
	 */
	public const STATUS_PROVIDER = 'provider';

	/**
	 * A row for an app without a provider.
	 */
	public const STATUS_NONE = 'none';

	/**
	 * A row for an app known to integrate without a provider.
	 */
	public const STATUS_ENDPOINTS = 'endpoints';

	/**
	 * A row for an app whose provider threw.
	 */
	public const STATUS_ERROR = 'error';

	/**
	 * The parts of a contribution that are counted.
	 */
	public const SECTIONS = ['collections', 'pages', 'actions'];

	/**
	 * Apps that integrate with the portal without a provider, and how.
	 *
	 * @var array<string, string>
	 */
	public const PROVIDERLESS_PATTERNS = [
		'opencatalogi' => 'public search and publication endpoints',
	];

	/**
	 * Finds each app's provider.
	 *
	 * @var PortalProviderLocator
	 */
	private readonly PortalProviderLocator $locator;

	/**
	 * Constructor.
	 *
	 * @param IAppManager                $appManager Lists the enabled apps.
	 * @param ContainerInterface         $container  Constructs the providers.
	 * @param LoggerInterface            $logger     The logger.
	 * @param ProviderAudiencesInterface $providerAudiences The ProviderAudiences helper.
	 * @param PortalProviderLocator|null $locator    Finds providers; built from the above when null.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ProviderAudiencesInterface $providerAudiences,
		?PortalProviderLocator $locator = null,
	) {
		$this->locator = ($locator ?? new PortalProviderLocator(appManager: $appManager, container: $container, logger: $logger));
	}//end __construct()

	/**
	 * The census: one row per enabled app, in app id order.
	 *
	 * Each row is `{app, status, pattern, error, audiences}`; `audiences` is a
	 * list of `{audience, collections, pages, actions}` counted for a synthetic
	 * subject of that audience, so no real person's data is read.
	 *
	 * @return array<int, array<string, mixed>> The rows, in app id order.
	 *
	 * @spec openspec/changes/contributing-apps-census/specs/portal-contribution-contract/spec.md#requirement-an-administrator-can-see-which-apps-contribute-to-the-portal-req-cac-001
	 */
	public function rows(): array {
		$apps = array_values(array_unique(array_map('strval', $this->appManager->getEnabledApps())));
		sort($apps, SORT_STRING);

		$rows = [];
		foreach ($apps as $appId) {
			$rows[] = $this->rowFor(appId: $appId);
		}

		return $rows;
	}//end rows()

	/**
	 * The row for one app; a provider that throws gives an error row, never an exception.
	 *
	 * @param string $appId The app id.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/contributing-apps-census/specs/portal-contribution-contract/spec.md#requirement-an-administrator-can-see-which-apps-contribute-to-the-portal-req-cac-001
	 */
	private function rowFor(string $appId): array {
		$row = ['app' => $appId, 'status' => self::STATUS_NONE, 'pattern' => '', 'error' => '', 'audiences' => []];

		try {
			$provider = $this->locator->locate(appId: $appId);
			if ($provider === null) {
				return $this->withoutProvider(row: $row);
			}

			$row['status'] = self::STATUS_PROVIDER;
			foreach ($this->providerAudiences->served(provider: $provider) as $audience) {
				$row['audiences'][] = $this->countsFor(provider: $provider, audience: $audience);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: contribution census could not read a provider', ['app' => $appId, 'exception' => $e::class]);
			$row['status'] = self::STATUS_ERROR;
			$row['error']  = $e::class;
		}

		return $row;
	}//end rowFor()

	/**
	 * The row of an app without a provider: a known pattern, or contributing nothing.
	 *
	 * @param array<string, mixed> $row The row so far.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function withoutProvider(array $row): array {
		$pattern = (self::PROVIDERLESS_PATTERNS[$row['app']] ?? '');
		if ($pattern !== '') {
			$row['status']  = self::STATUS_ENDPOINTS;
			$row['pattern'] = $pattern;
		}

		return $row;
	}//end withoutProvider()

	/**
	 * How many collections, pages and actions a provider declares for a synthetic subject of one audience.
	 *
	 * @param object $provider The provider.
	 * @param string $audience The audience.
	 *
	 * @return array{audience: string, collections: int, pages: int, actions: int}
	 */
	private function countsFor(object $provider, string $audience): array {
		$contribution = $this->locator->contributionOf(provider: $provider, subject: ['audience' => $audience]);

		$counts = ['audience' => $audience];
		foreach (self::SECTIONS as $section) {
			$entries = [];
			if (is_array($contribution) === true && is_array($contribution[$section] ?? null) === true) {
				$entries = $contribution[$section];
			}

			$counts[$section] = count($entries);
		}

		return $counts;
	}//end countsFor()
}//end class
