<?php

/**
 * Portaliq Traffic Error Stats.
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
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-script-errors-must-be-reported-without-the-stack-or-the-query-string
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * The script errors of a day: one row per distinct message and source
 * file, with how often it happened and on which pages.
 *
 * The client sends `js_error` with the message, the source file's host
 * and path (never its query string), the line, the column and a short
 * hash of the stack (never the stack). This class only counts what it is
 * handed, so a client that sent more would still store more; that is the
 * validator's job (params are bounded there) and the client's promise.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-script-errors-must-be-reported-without-the-stack-or-the-query-string
 */
class TrafficErrorStats {

	/**
	 * The event name.
	 */
	public const EVENT = 'js_error';

	/**
	 * The most distinct errors kept, and the most pages listed per error.
	 */
	private const TOP = 100;

	/**
	 * The most pages listed per error.
	 */
	private const PAGES = 10;

	/**
	 * The error rows of the day's sessions, ranked by hits.
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 *
	 * @return array<int, array{message: string, source: string, hits: int, pages: string[]}> The rows.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-script-errors-must-be-reported-without-the-stack-or-the-query-string
	 */
	public function rows(array $sessions): array {
		$errors = [];
		foreach ($sessions as $session) {
			foreach (($session['events'] ?? []) as $event) {
				if (is_array($event) === false || ($event['name'] ?? '') !== self::EVENT) {
					continue;
				}

				$params = $event['params'] ?? [];
				if (is_array($params) === false) {
					$params = [];
				}

				$message = trim((string)($params['message'] ?? ''));
				if ($message === '') {
					$message = 'Script error';
				}

				$source = trim((string)($params['source'] ?? ''));
				$id = $message . "\0" . $source;
				$row = $errors[$id] ?? ['message' => $message, 'source' => $source, 'hits' => 0, 'pages' => []];
				$row['hits']++;
				$page = trim((string)($event['pagePath'] ?? ''));
				if ($page !== '' && in_array($page, $row['pages'], true) === false && count($row['pages']) < self::PAGES) {
					$row['pages'][] = $page;
				}

				$errors[$id] = $row;
			}
		}

		$out = array_values($errors);
		usort($out, static fn (array $a, array $b): int => [$b['hits'], $a['message']] <=> [$a['hits'], $b['message']]);

		return array_slice($out, 0, self::TOP);
	}
}
