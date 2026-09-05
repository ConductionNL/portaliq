<?php

/**
 * Portaliq Traffic Recording Service.
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
 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-session-recording-must-be-off-by-default-consented-and-bounded
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\TrafficConfigResolver;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Turns one posted recording chunk into a stored, bounded, masked
 * recording, or into a named refusal.
 *
 * FOUR GATES BEFORE A BYTE IS KEPT. The portal measures at all; the
 * portal's operator switched session recording on, with its warning; the
 * portal is a site this app serves (an external site's DOM is not ours
 * to record, and the recorder never runs there); and the visitor
 * consented when the portal requires consent. Then two budgets: a chunk
 * of at most 256 KB, and a visit of at most 2 MB, after which the visit
 * is full and further chunks are refused and counted.
 *
 * Every chunk passes through the mask before it is stored, whatever the
 * recorder promised.
 *
 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-session-recording-must-be-off-by-default-consented-and-bounded
 */
class TrafficRecordingService {

	/**
	 * The most bytes one chunk may carry, after masking: 256 KB.
	 */
	public const MAX_CHUNK_BYTES = 262144;

	/**
	 * The most bytes one visit may accumulate: 2 MB.
	 */
	public const MAX_RECORDING_BYTES = 2097152;

	/**
	 * The most events one chunk may carry.
	 */
	public const MAX_EVENTS = 5000;

	/**
	 * The most page paths kept on one recording.
	 */
	private const MAX_PAGES = 50;

	/**
	 * Constructor.
	 *
	 * @param TrafficConfigResolver $config  Resolves the portal's configuration.
	 * @param TrafficRecordingStore $store   Reads and writes recordings.
	 * @param TrafficRecordingMask  $mask    Reduces a chunk to what may be stored.
	 * @param TrafficMetrics        $metrics Counts what happened.
	 * @param ITimeFactory          $time    The clock.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficConfigResolver $config,
		private readonly TrafficRecordingStore $store,
		private readonly TrafficRecordingMask $mask,
		private readonly TrafficMetrics $metrics,
		private readonly ITimeFactory $time,
	) {
	}

	/**
	 * Store one chunk for a resolved portal.
	 *
	 * @param array<string, mixed> $portal  The portal record.
	 * @param array<string, mixed> $body    The posted chunk: `recording`, `events`, `page`, `sessionId`, `elapsed`.
	 * @param array<string, mixed> $context `consent` => bool.
	 *
	 * @return array{ok: bool, reason: string} The outcome; the reason is '' when stored.
	 *
	 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-session-recording-must-be-off-by-default-consented-and-bounded
	 */
	public function ingest(array $portal, array $body, array $context = []): array {
		$config = $this->config->resolve(portal: $portal);
		$reason = $this->refusal(config: $config, body: $body, consent: (($context['consent'] ?? false) === true));
		if ($reason !== null) {
			return $this->refuse(reason: $reason);
		}

		$events = $this->mask->events(events: $body['events']);
		if ($events === []) {
			return $this->refuse(reason: 'recording-empty');
		}

		$chunkBytes = strlen((string)json_encode($events));
		if ($chunkBytes > self::MAX_CHUNK_BYTES) {
			return $this->refuse(reason: 'recording-chunk-too-large');
		}

		$slug = (string)($portal['slug'] ?? '');
		$recordingId = (string)$body['recording'];
		$existing = $this->store->find(portal: $slug, recordingId: $recordingId);
		$now = $this->now();
		$record = $existing ?? $this->fresh(slug: $slug, recordingId: $recordingId, body: $body, config: $config, now: $now);
		if ((int)($record['bytes'] ?? 0) + $chunkBytes > self::MAX_RECORDING_BYTES) {
			return $this->refuse(reason: 'recording-full');
		}

		$record = $this->appended(record: $record, body: $body, events: $events, bytes: $chunkBytes, now: $now);
		$uuid = null;
		if ($existing !== null) {
			$uuid = $this->store->uuidOf(row: $existing);
			unset($record['@self']);
		}

		if ($uuid === '') {
			$uuid = null;
		}

		if ($this->store->save(recording: $record, uuid: $uuid) === false) {
			return $this->refuse(reason: 'storage-failed');
		}

		$this->metrics->accepted(count: 1);

		return ['ok' => true, 'reason' => ''];
	}

	/**
	 * Why a chunk is refused before it is looked at, or null.
	 *
	 * @param array<string, mixed> $config  The resolved configuration.
	 * @param array<string, mixed> $body    The posted chunk.
	 * @param bool                 $consent The visitor's consent state.
	 *
	 * @return string|null The reason.
	 */
	private function refusal(array $config, array $body, bool $consent): ?string {
		return $this->portalRefusal(config: $config, consent: $consent) ?? $this->bodyRefusal(body: $body);
	}

	/**
	 * The four gates on the portal and the visitor, or null.
	 *
	 * @param array<string, mixed> $config  The resolved configuration.
	 * @param bool                 $consent The visitor's consent state.
	 *
	 * @return string|null The reason.
	 */
	private function portalRefusal(array $config, bool $consent): ?string {
		if (($config['enabled'] ?? false) !== true) {
			return 'measurement-disabled';
		}

		if (($config['sensitive']['sessionRecording'] ?? false) !== true) {
			return 'sensitive-off';
		}

		if (($config['kind'] ?? 'site') === 'external') {
			return 'external-portal';
		}

		if (($config['consentRequired'] ?? false) === true && $consent === false) {
			return 'event-requires-consent';
		}

		return null;
	}

	/**
	 * The shape of the chunk itself, or null when it is usable.
	 *
	 * @param array<string, mixed> $body The posted chunk.
	 *
	 * @return string|null The reason.
	 */
	private function bodyRefusal(array $body): ?string {
		$recordingId = $body['recording'] ?? null;
		if (is_string($recordingId) === false || preg_match('/^[a-f0-9]{16,64}$/', $recordingId) !== 1) {
			return 'malformed-recording';
		}

		$events = $body['events'] ?? null;
		if (is_array($events) === false || $events === [] || count($events) > self::MAX_EVENTS) {
			return 'malformed-recording';
		}

		return null;
	}

	/**
	 * A new recording, before its first chunk.
	 *
	 * @param string               $slug        The portal slug.
	 * @param string               $recordingId The recorder's id.
	 * @param array<string, mixed> $body        The posted chunk.
	 * @param array<string, mixed> $config      The resolved configuration.
	 * @param DateTimeImmutable    $now         The clock.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function fresh(string $slug, string $recordingId, array $body, array $config, DateTimeImmutable $now): array {
		$sessionId = $body['sessionId'] ?? '';
		if (is_string($sessionId) === false) {
			$sessionId = '';
		}

		return [
			'portal' => $slug,
			'recordingId' => $recordingId,
			'sessionId' => mb_substr(trim($sessionId), 0, 64),
			'startedAt' => $now->format('Y-m-d\TH:i:s.v\Z'),
			'lastChunkAt' => $now->format('Y-m-d\TH:i:s.v\Z'),
			'expires' => $now->modify('+' . (int)$config['retentionDays'] . ' days')->format('Y-m-d\TH:i:s\Z'),
			'pages' => [],
			'durationMs' => 0,
			'bytes' => 0,
			'chunks' => [],
		];
	}

	/**
	 * The record with one more chunk on it.
	 *
	 * @param array<string, mixed>             $record The record.
	 * @param array<string, mixed>             $body   The posted chunk.
	 * @param array<int, array<string, mixed>> $events The masked events.
	 * @param int                              $bytes  The chunk's size.
	 * @param DateTimeImmutable                $now    The clock.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function appended(array $record, array $body, array $events, int $bytes, DateTimeImmutable $now): array {
		$page = $this->page(value: ($body['page'] ?? null));
		$pages = (array)($record['pages'] ?? []);
		if ($page !== '' && in_array($page, $pages, true) === false && count($pages) < self::MAX_PAGES) {
			$pages[] = $page;
		}

		$chunks = (array)($record['chunks'] ?? []);
		$chunks[] = ['at' => $now->format('Y-m-d\TH:i:s.v\Z'), 'page' => $page, 'events' => $events];

		$record['pages'] = array_values($pages);
		$record['chunks'] = array_values($chunks);
		$record['bytes'] = (int)($record['bytes'] ?? 0) + $bytes;
		$record['durationMs'] = max((int)($record['durationMs'] ?? 0), $this->elapsed(value: ($body['elapsed'] ?? null)));
		$record['lastChunkAt'] = $now->format('Y-m-d\TH:i:s.v\Z');

		return $record;
	}

	/**
	 * A page path, never a query string.
	 *
	 * @param mixed $value The posted path.
	 *
	 * @return string The path, or ''.
	 */
	private function page(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return mb_substr(substr($value, 0, strcspn($value, '?#')), 0, 256);
	}

	/**
	 * The milliseconds the visit has lasted, as the recorder says.
	 *
	 * @param mixed $value The posted value.
	 *
	 * @return int The milliseconds, at least 0.
	 */
	private function elapsed(mixed $value): int {
		if (is_int($value) === true) {
			return max(0, $value);
		}

		if (is_float($value) === true && is_finite($value) === true) {
			return max(0, (int)$value);
		}

		return 0;
	}

	/**
	 * Count and return one refusal.
	 *
	 * @param string $reason The reason.
	 *
	 * @return array{ok: bool, reason: string} The outcome.
	 */
	private function refuse(string $reason): array {
		$this->metrics->refused(reasons: [$reason => 1]);

		return ['ok' => false, 'reason' => $reason];
	}

	/**
	 * The clock, in UTC.
	 *
	 * @return DateTimeImmutable Now.
	 */
	private function now(): DateTimeImmutable {
		return DateTimeImmutable::createFromMutable($this->time->getDateTime())->setTimezone(new DateTimeZone('UTC'));
	}
}
