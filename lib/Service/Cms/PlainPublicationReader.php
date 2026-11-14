<?php

/**
 * Reads opencatalogi's public publication endpoint for the plain site page.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\Service\InstanceLoopback;
use OCP\App\IAppManager;
use Throwable;

/**
 * The search and the by-id read the search and detail blocks make in the
 * browser, made by the server for a visitor without JavaScript.
 *
 * Read from opencatalogi's `FederationController` on `development`:
 * - `publications()` at `/api/federation/publications` answers
 *   `{results, total, pages}`; it takes `_search`, `_page` and `_limit`.
 * - `publication()` at `/api/federation/publications/{id}` answers the object
 *   itself with the status the service gives (404 for none, 403 for one the
 *   caller may not read).
 * - `publicationAttachments()` at `/api/federation/publications/{id}/attachments`
 *   answers a list or `{results}` of files.
 *
 * Field names follow the blocks (`src/site/lib/federatedSearch.js::toResult()`,
 * `src/site/lib/publicationDetail.js`): a row's id is `@self.id` else `id`; its
 * title `name`, else `@self.name`, else `@self.title` (the detail block reads
 * `title` first); its summary `@self.summary`, else `summary`, else
 * `description`; its date `publicationDate`, else `@self.published`. A file's
 * name is `title` else `name`, its type `extension` else the name's extension,
 * its size `size`, its address `downloadUrl` else `accessUrl` (http(s) or a
 * path on this instance only).
 *
 * Fail closed: every call is anonymous (no cookie, no `Authorization`), only a
 * relative path on this instance is ever called, and a failure is
 * `unavailable`, never an empty result.
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-publications-can-be-searched-without-javascript-req-shj-003
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-when-publications-cannot-be-read-the-page-says-so-req-shj-005
 */
class PlainPublicationReader {

	/**
	 * Seconds a call may take before the publications count as unavailable.
	 *
	 * @var int
	 */
	private const TIMEOUT = 5;

	/**
	 * The app that serves the endpoint.
	 *
	 * @var string
	 */
	private const CATALOGUE_APP = 'opencatalogi';

	/**
	 * Characters of a summary kept, as the search block keeps them.
	 *
	 * @var int
	 */
	private const SUMMARY_LENGTH = 280;

	/**
	 * Constructor.
	 *
	 * @param InstanceLoopback $loopback   The call to this instance.
	 * @param IAppManager      $appManager Whether opencatalogi is there.
	 */
	public function __construct(
		private readonly InstanceLoopback $loopback,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * One page of search results.
	 *
	 * @param string $endpoint The widget's endpoint, a path on this instance.
	 * @param string $query    The search term, '' for all.
	 * @param int    $page     The page, from 1.
	 * @param int    $pageSize Rows per page.
	 *
	 * @return array{state: string, total: int, results: list<array{id: string, title: string, date: string, summary: string}>}
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.1
	 */
	public function search(string $endpoint, string $query, int $page, int $pageSize): array {
		$unavailable = ['state' => 'unavailable', 'total' => 0, 'results' => []];
		$params = ['_limit' => (string)max(1, $pageSize), '_page' => (string)max(1, $page)];
		if ($query !== '') {
			$params['_search'] = $query;
		}

		$answer = $this->get(endpoint: $endpoint, suffix: '', params: $params);
		if ($answer === null || $answer['status'] !== 200 || is_array($answer['body']) === false) {
			return $unavailable;
		}

		$results = [];
		foreach ((array)($answer['body']['results'] ?? []) as $row) {
			if (is_array($row) === true) {
				$results[] = $this->resultOf(row: $row);
			}
		}

		return ['state' => 'ok', 'total' => max(0, (int)($answer['body']['total'] ?? count($results))), 'results' => $results];
	}//end search()

	/**
	 * One publication and its documents.
	 *
	 * @param string $endpoint The widget's endpoint, a path on this instance.
	 * @param string $id       The publication's id.
	 *
	 * @return array{state: string, publication?: array, documents?: list<array{name: string, type: string, size: int, href: string}>}
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.1
	 */
	public function publication(string $endpoint, string $id): array {
		$id = trim($id);
		if ($id === '') {
			return ['state' => 'not-found'];
		}

		$suffix = '/' . rawurlencode($id);
		$answer = $this->get(endpoint: $endpoint, suffix: $suffix, params: []);
		if ($answer === null || $answer['status'] >= 500) {
			return ['state' => 'unavailable'];
		}

		$body = $answer['body'];
		if ($answer['status'] !== 200 || is_array($body) === false || $this->matches(row: $body, id: $id) === false) {
			// 404, 403 and an answer that is not this publication all read the
			// same: one cause must not be told from the other.
			return ['state' => 'not-found'];
		}

		return ['state' => 'ok', 'publication' => $body, 'documents' => $this->documents(endpoint: $endpoint, suffix: $suffix)];
	}//end publication()

	/**
	 * The name of a theme, or '' when it cannot be read.
	 *
	 * @param string $endpoint The themes endpoint, a path on this instance.
	 * @param string $id       The theme's id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.3
	 */
	public function themeName(string $endpoint, string $id): string {
		$answer = $this->get(endpoint: $endpoint, suffix: '/' . rawurlencode($id), params: []);
		if ($answer === null || $answer['status'] !== 200 || is_array($answer['body']) === false) {
			return '';
		}

		return trim((string)($answer['body']['title'] ?? $answer['body']['name'] ?? ''));
	}//end themeName()

	/**
	 * Whether an endpoint is one the server may call: a path on this instance,
	 * never an address with a scheme or a host.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.1
	 */
	public function isLocal(string $endpoint): bool {
		$endpoint = trim($endpoint);

		return preg_match('~^/(?![/\\\\])[^\s?#]*$~', $endpoint) === 1 && str_contains($endpoint, '..') === false;
	}//end isLocal()

	/**
	 * The documents of a publication; none when they cannot be read.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $suffix   `/<id>`.
	 *
	 * @return list<array{name: string, type: string, size: int, href: string}>
	 */
	private function documents(string $endpoint, string $suffix): array {
		$answer = $this->get(endpoint: $endpoint, suffix: $suffix . '/attachments', params: []);
		if ($answer === null || $answer['status'] !== 200 || is_array($answer['body']) === false) {
			return [];
		}

		$files = $answer['body'];
		if (array_is_list($files) === false) {
			$files = (array)($files['results'] ?? []);
		}

		$documents = [];
		foreach ($files as $file) {
			if (is_array($file) === true) {
				$documents[] = $this->documentOf(file: $file);
			}
		}

		return $documents;
	}//end documents()

	/**
	 * One anonymous GET, or null when it was not made or did not answer.
	 *
	 * @param string                $endpoint The endpoint.
	 * @param string                $suffix   What follows it.
	 * @param array<string, string> $params   The query.
	 *
	 * @return array{status: int, body: mixed}|null
	 */
	private function get(string $endpoint, string $suffix, array $params): ?array {
		if ($this->isLocal(endpoint: $endpoint) === false || $this->catalogueIsThere() === false) {
			return null;
		}

		$path = rtrim(trim($endpoint), '/') . $suffix;
		if ($params !== []) {
			$path .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
		}

		try {
			$response = $this->loopback->request(
				method: 'GET',
				path: $path,
				options: [
					// Only these headers: no cookie and no Authorization, so the
					// endpoint answers what any anonymous visitor may read.
					'headers' => ['Accept' => 'application/json'],
					'timeout' => self::TIMEOUT,
					'connect_timeout' => self::TIMEOUT,
					'http_errors' => false,
				]
			);
		} catch (Throwable) {
			return null;
		}

		$body = $response->getBody();
		$decoded = null;
		if (is_string($body) === true) {
			$decoded = json_decode($body, true);
		}

		return ['status' => (int)$response->getStatusCode(), 'body' => $decoded];
	}//end get()

	/**
	 * Whether opencatalogi is enabled here.
	 *
	 * @return bool
	 */
	private function catalogueIsThere(): bool {
		try {
			return $this->appManager->isEnabledForUser(self::CATALOGUE_APP) === true;
		} catch (Throwable) {
			return false;
		}
	}//end catalogueIsThere()

	/**
	 * One result row as the plain page shows it.
	 *
	 * @param array<string, mixed> $row The endpoint's row.
	 *
	 * @return array{id: string, title: string, date: string, summary: string}
	 */
	private function resultOf(array $row): array {
		$self = (array)($row['@self'] ?? []);

		return [
			'id' => $this->text(values: [$self['id'] ?? null, $row['id'] ?? null]),
			'title' => $this->text(values: [$row['name'] ?? null, $self['name'] ?? null, $self['title'] ?? null, $row['title'] ?? null]),
			'date' => $this->text(values: [$row['publicationDate'] ?? null, $self['published'] ?? null]),
			'summary' => mb_substr($this->text(values: [$self['summary'] ?? null, $row['summary'] ?? null, $row['description'] ?? null]), 0, self::SUMMARY_LENGTH),
		];
	}//end resultOf()

	/**
	 * One file as the plain page links it.
	 *
	 * @param array<string, mixed> $file The endpoint's file.
	 *
	 * @return array{name: string, type: string, size: int, href: string}
	 */
	private function documentOf(array $file): array {
		$name = $this->text(values: [$file['title'] ?? null, $file['name'] ?? null]);
		$type = strtoupper($this->text(values: [$file['extension'] ?? null]));
		if ($type === '' && preg_match('/\.([A-Za-z0-9]{1,8})$/', $name, $match) === 1) {
			$type = strtoupper($match[1]);
		}

		$href = $this->safeLink(value: $file['downloadUrl'] ?? null);
		if ($href === '') {
			$href = $this->safeLink(value: $file['accessUrl'] ?? null);
		}

		$size = 0;
		if (is_numeric($file['size'] ?? null) === true) {
			$size = max(0, (int)$file['size']);
		}

		if ($name === '') {
			$name = 'Document';
		}

		return ['name' => $name, 'type' => $type, 'size' => $size, 'href' => $href];
	}//end documentOf()

	/**
	 * Whether a by-id answer is the publication asked for.
	 *
	 * @param array<string, mixed> $row The answer.
	 * @param string               $id  The id asked for.
	 *
	 * @return bool
	 */
	private function matches(array $row, string $id): bool {
		$self = (array)($row['@self'] ?? []);

		return (string)($self['id'] ?? '') === $id || (string)($row['id'] ?? '') === $id;
	}//end matches()

	/**
	 * The first value that is a non-blank string or a number, trimmed.
	 *
	 * @param array<int, mixed> $values The candidates, preferred first.
	 *
	 * @return string
	 */
	private function text(array $values): string {
		foreach ($values as $value) {
			if (is_string($value) === true || is_int($value) === true || is_float($value) === true) {
				$value = trim((string)$value);
				if ($value !== '') {
					return $value;
				}
			}
		}

		return '';
	}//end text()

	/**
	 * An http(s) address or a path on this instance, else ''.
	 *
	 * @param mixed $value The stored address.
	 *
	 * @return string
	 */
	private function safeLink(mixed $value): string {
		$text = '';
		if (is_string($value) === true) {
			$text = trim($value);
		}

		if (preg_match('#^https?://#i', $text) === 1 || preg_match('#^/(?!/)#', $text) === 1) {
			return $text;
		}

		return '';
	}//end safeLink()
}//end class
