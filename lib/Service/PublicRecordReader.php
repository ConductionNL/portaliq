<?php

/**
 * Portaliq Public Record Reader
 *
 * Reads a contributed public record list and one record from it, through the
 * provider methods the contribution declares, with no subject.
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
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Contribution\PublicRecordsNormaliser;
use OCA\Portaliq\Contribution\TimelineProviderMethod;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The list keeps at most 500 entries and only the contract's keys; a record is
 * fetched only for an id the list holds. Answers are cached for five minutes
 * per app and list, because they hold no personal data beyond public office holders.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- locator, cache, logger and the two normalisers.
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t2
 */
class PublicRecordReader {

	/**
	 * The most list entries kept.
	 *
	 * @var int
	 */
	public const MAX_ENTRIES = 500;

	/**
	 * Cache lifetime in seconds.
	 *
	 * @var int
	 */
	private const TTL = 300;

	/**
	 * The keys a list entry keeps.
	 *
	 * @var string[]
	 */
	private const LIST_KEYS = ['id', 'title', 'subtitle', 'image'];

	/**
	 * The distributed cache.
	 *
	 * @var ICache
	 */
	private readonly ICache $cache;

	/**
	 * Holds a provider's answer to the contract's plain shape.
	 *
	 * @var PublicRecordShape
	 */
	private readonly PublicRecordShape $shape;

	/**
	 * Constructor.
	 *
	 * @param PortalProviderLocator $locator Finds the contributing app's provider.
	 * @param ICacheFactory $cacheFactory Creates the distributed cache.
	 * @param LoggerInterface $logger Records a provider that failed.
	 */
	public function __construct(
		private readonly PortalProviderLocator $locator,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed('portaliq_public_records');
		$this->shape = new PublicRecordShape();
	}//end __construct()

	/**
	 * The entries of one record list, or null when the app declares no such list.
	 *
	 * @param string $app The contributing app.
	 * @param string $list The list id.
	 *
	 * @return array<int, array<string, string>>|null The entries.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t2
	 */
	public function entries(string $app, string $list): ?array {
		$key    = 'list|'.$app.'|'.$list;
		$cached = $this->cache->get($key);
		if (is_array($cached) === true) {
			return $cached;
		}

		$declared = $this->declared(app: $app, list: $list);
		if ($declared === null) {
			return null;
		}

		$answer = $this->call(provider: $declared['provider'], method: $declared['entry']['listProvider'], argument: null, app: $app);
		if (is_array($answer) === false) {
			return [];
		}

		$entries = $this->listEntries(answer: $answer);
		$this->cache->set($key, $entries, self::TTL);
		return $entries;
	}//end entries()

	/**
	 * One record, or null when the list does not hold the id.
	 *
	 * The record provider is not called for an id the list answer lacks.
	 *
	 * @param string $app The contributing app.
	 * @param string $list The list id.
	 * @param string $id The record id.
	 *
	 * @return array<string, mixed>|null The record.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t2
	 */
	public function record(string $app, string $list, string $id): ?array {
		$entries = $this->entries(app: $app, list: $list);
		if ($entries === null || in_array($id, array_column($entries, 'id'), true) === false) {
			return null;
		}

		$key    = 'record|'.$app.'|'.$list.'|'.$id;
		$cached = $this->cache->get($key);
		if (is_array($cached) === true) {
			return $cached;
		}

		$declared = $this->declared(app: $app, list: $list);
		if ($declared === null) {
			return null;
		}

		$answer = $this->call(provider: $declared['provider'], method: $declared['entry']['recordProvider'], argument: $id, app: $app);
		if (is_array($answer) === false) {
			return null;
		}

		$record = $this->shape->record(answer: $answer);
		$this->cache->set($key, $record, self::TTL);
		return $record;
	}//end record()

	/**
	 * The provider and the validated declaration of one list.
	 *
	 * @param string $app The app.
	 * @param string $list The list id.
	 *
	 * @return array{provider: object, entry: array<string, string>}|null
	 */
	private function declared(string $app, string $list): ?array {
		$provider = $this->locator->locate(appId: $app);
		if ($provider === null) {
			return null;
		}

		$contribution = $this->contribution(provider: $provider, app: $app);
		$kept         = (new PublicRecordsNormaliser())->normalise(entries: ($contribution['publicRecords'] ?? null), provider: $provider);
		foreach ($kept as $entry) {
			if ($entry['id'] === $list) {
				return ['provider' => $provider, 'entry' => $entry];
			}
		}

		return null;
	}//end declared()

	/**
	 * What the provider declares, asked with no subject.
	 *
	 * @param object $provider The provider.
	 * @param string $app The app.
	 *
	 * @return array<string, mixed> The contribution, or an empty one.
	 */
	private function contribution(object $provider, string $app): array {
		foreach ($this->audiences(provider: $provider) as $audience) {
			try {
				$answer = $this->locator->contributionOf(provider: $provider, subject: ['audience' => $audience]);
			} catch (Throwable $e) {
				$this->logger->warning('Portaliq: public records declaration failed', ['app' => $app, 'reason' => $e->getMessage()]);
				continue;
			}

			if (is_array($answer) === true && is_array(($answer['publicRecords'] ?? null)) === true) {
				return $answer;
			}
		}

		return [];
	}//end contribution()

	/**
	 * The audiences a provider serves.
	 *
	 * @param object $provider The provider.
	 *
	 * @return array<int, string>
	 */
	private function audiences(object $provider): array {
		if (method_exists($provider, 'getAudiences') === true && is_array($provider->getAudiences()) === true) {
			return array_values(array_filter($provider->getAudiences(), 'is_string'));
		}

		if (method_exists($provider, 'getAudience') === true && is_string($provider->getAudience()) === true) {
			return [$provider->getAudience()];
		}

		return [];
	}//end audiences()

	/**
	 * Call a declared provider method, never letting a failure through.
	 *
	 * @param object $provider The provider.
	 * @param string $method The declared method.
	 * @param string|null $argument The record id, or null for a list.
	 * @param string $app The app, for the log.
	 *
	 * @return mixed What the provider answered, or null.
	 */
	private function call(object $provider, string $method, ?string $argument, string $app): mixed {
		try {
			if ($argument === null) {
				return $provider->{$method}();
			}

			return $provider->{$method}($argument);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: public records provider failed', ['app' => $app, 'method' => $method, 'reason' => $e->getMessage()]);
			return null;
		}
	}//end call()

	/**
	 * Keep at most 500 entries, each with only the contract's keys as plain text.
	 *
	 * @param array<int, mixed> $answer The provider's list.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function listEntries(array $answer): array {
		$entries = [];
		foreach ($answer as $row) {
			if (is_array($row) === false) {
				continue;
			}

			if ($this->shape->text(value: ($row['id'] ?? null)) === '' || $this->shape->text(value: ($row['title'] ?? null)) === '') {
				continue;
			}

			$entry = [];
			foreach (self::LIST_KEYS as $key) {
				$value = $this->shape->text(value: ($row[$key] ?? null));
				if ($value !== '') {
					$entry[$key] = $value;
				}
			}

			$entries[] = $entry;
			if (count($entries) >= self::MAX_ENTRIES) {
				break;
			}
		}

		return $entries;
	}//end listEntries()

}//end class
