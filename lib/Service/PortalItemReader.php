<?php
/**
 * Portaliq Item Reader (my-dossiers)
 *
 * Asks the contributing app for one object's items through the provider
 * method its `itemList` names, after the controller proved the object is the
 * resident's. Keeps per item only what the portal shows, and drops a link
 * that is not https or a path on this instance.
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
 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-an-item-list-read-from-its-app-req-myd-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Contribution\TimelineProviderMethod;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and sanitises one object's items.
 *
 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-an-item-list-read-from-its-app-req-myd-001
 */
class PortalItemReader {
	/**
	 * Constructor.
	 *
	 * @param PortalProviderLocator $locator Finds the app's provider.
	 * @param LoggerInterface       $logger  The logger.
	 */
	public function __construct(
		private readonly PortalProviderLocator $locator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The items, or null when the method is missing, fails or answers no list.
	 *
	 * @param string $appId  The contributing app.
	 * @param string $method The provider method.
	 * @param string $id     The object id.
	 *
	 * @return array<int, array{id: string, title: string, url: string, note: string, public: bool, addedAt: string}>|null
	 *
	 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-an-item-list-read-from-its-app-req-myd-001
	 */
	public function items(string $appId, string $method, string $id): ?array {
		$provider = $this->locator->locate(appId: $appId);
		if ($provider === null || (new TimelineProviderMethod())->callableOn(provider: $provider, method: $method) === false) {
			return null;
		}

		try {
			$items = $provider->{$method}($id);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: item list provider failed', ['app' => $appId, 'method' => $method, 'reason' => $e->getMessage()]);
			return null;
		}

		if (is_array($items) === false) {
			return null;
		}

		$out = [];
		foreach ($items as $item) {
			if (is_array($item) === true) {
				$out[] = $this->item(item: $item);
			}
		}

		return $out;
	}//end items()

	/**
	 * One item, reduced to the keys the portal shows.
	 *
	 * @param array<string, mixed> $item The item as the app returned it.
	 *
	 * @return array{id: string, title: string, url: string, note: string, public: bool, addedAt: string}
	 */
	private function item(array $item): array {
		return [
			'id' => $this->text(value: ($item['id'] ?? '')),
			'title' => $this->text(value: ($item['title'] ?? '')),
			'url' => $this->safeUrl(value: ($item['url'] ?? '')),
			'note' => $this->text(value: ($item['note'] ?? '')),
			'public' => (($item['public'] ?? true) !== false),
			'addedAt' => $this->text(value: ($item['addedAt'] ?? '')),
		];
	}//end item()

	/**
	 * A scalar as text, anything else as ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === true || is_int($value) === true || is_float($value) === true) {
			return (string)$value;
		}

		return '';
	}//end text()

	/**
	 * An https URL or an instance-local path, else ''.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return string
	 */
	private function safeUrl(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		$url = trim($value);
		if (preg_match('#^https://[^\s]+$#i', $url) === 1 || preg_match('#^/(?!/)[^\s]*$#', $url) === 1) {
			return $url;
		}

		return '';
	}//end safeUrl()
}//end class
