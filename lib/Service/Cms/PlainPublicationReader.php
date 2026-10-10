<?php

/**
 * Portaliq plain publication reader
 *
 * Reads opencatalogi's public publication endpoint on the server, for the
 * plain version of a site page.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-publications-can-be-searched-without-javascript-req-shj-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\Service\InstanceLoopback;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The publication reads the site's search and detail blocks make in the
 * browser, made once on the server instead, as an anonymous visitor.
 *
 * Endpoints, read on opencatalogi `development` (appinfo/routes.php,
 * FederationController): `GET /api/federation/publications` (publications(),
 * `{results, total, page, pages}`, with `_search`, `_page`, `_limit`),
 * `GET /api/federation/publications/{id}` (publication(), the object itself)
 * and `GET /api/federation/publications/{id}/attachments` (publicationAttachments(),
 * `{results: [file]}`), all `@PublicPage`. Themes by name come from
 * `GET /api/themes/{id}` (`title` or `name`), as the detail block reads them.
 *
 * Field names follow the blocks: a result is `@self.id` or `id`, `name` (else
 * `@self.name`, `@self.title`), `publicationDate` (else `@self.published`) and
 * `@self.summary` (else `description`), as `src/site/lib/federatedSearch.js`
 * `toResult()`; a publication's summary is `summary` else `description`, its
 * category `wooCategory` and its themes `themes`, and a document is `title`
 * (else `name`), `extension`, `size` and `downloadUrl` (else `accessUrl`), as
 * `src/site/lib/publicationDetail.js`.
 *
 * Fail closed: the call carries no cookie and no `Authorization` header, never
 * anything of the incoming request, and only a relative path on this instance
 * is ever called. An absolute endpoint, opencatalogi switched off, a transport
 * error or a 5xx all answer `unavailable`, never an empty list.
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-publications-can-be-searched-without-javascript-req-shj-003
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-a-publication-can-be-read-without-javascript-req-shj-004
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-when-publications-cannot-be-read-the-page-says-so-req-shj-005
 */
class PlainPublicationReader {

	/**
	 * Seconds the server waits for an answer (REQ-SHJ-005).
	 */
	public const TIMEOUT = 5;

	/**
	 * Where theme names are read, as the detail block's default.
	 */
	public const THEMES_ENDPOINT = '/index.php/apps/opencatalogi/api/themes';

	/**
	 * Constructor.
	 *
	 * @param InstanceLoopback $loopback   Calls this instance.
	 * @param IAppManager      $appManager Whether opencatalogi is switched on.
	 * @param LoggerInterface  $logger     Logs a failed read.
	 */
	public function __construct(
		private readonly InstanceLoopback $loopback,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One page of search results.
	 *
	 * @param string $endpoint The widget's endpoint.
	 * @param string $query    The term, '' for everything.
	 * @param int    $page     The page, from 1.
	 * @param int    $pageSize Results per page.
	 *
	 * @return array{state: string, total: int, results: list<array{id: string, title: string, date: string, summary: string}>}
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-publications-can-be-searched-without-javascript-req-shj-003
	 */
	public function search(string $endpoint, string $query, int $page, int $pageSize): array {
		$unavailable = ['state' => 'unavailable', 'total' => 0, 'results' => []];

		$params = ['_limit' => (string)max(1, $pageSize), '_page' => (string)max(1, $page)];
		if ($query !== '') {
			$params['_search'] = $query;
		}

		$answer = $this->get(endpoint: $endpoint, suffix: '', params: $params);
		if ($answer['status'] !== 200 || is_array($answer['body']) === false) {
			return $unavailable;
		}

		$results = [];
		foreach ((array)($answer['body']['results'] ?? []) as $row) {
			if (is_array($row) === true) {
				$results[] = $this->result(row: $row);
			}
		}

		return ['state' => 'ok', 'total' => (int)($answer['body']['total'] ?? count($results)), 'results' => $results];
	}//end search()

	/**
	 * One publication with its documents.
	 *
	 * @param string $endpoint The widget's endpoint.
	 * @param string $id       The publication id.
	 *
	 * @return array{state: string, publication?: array<string, mixed>,
	 *               documents?: list<array{name: string, type: string, size: int, href: string}>, themes?: list<string>}
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-a-publication-can-be-read-without-javascript-req-shj-004
	 */
	public function publication(string $endpoint, string $id): array {
		if (preg_match('/^[A-Za-z0-9._\-]{1,128}$/', $id) !== 1) {
			return ['state' => 'not-found'];
		}

		$answer = $this->get(endpoint: $endpoint, suffix: '/'.rawurlencode($id), params: []);
		if (in_array($answer['status'], [403, 404], true) === true) {
			return ['state' => 'not-found'];
		}

		if ($answer['status'] !== 200 || is_array($answer['body']) === false) {
			return ['state' => 'unavailable'];
		}

		$publication = $answer['body'];
		if (is_array($publication['results'] ?? null) === true) {
			$publication = (array)($publication['results'][0] ?? []);
		}

		$self = (array)($publication['@self'] ?? []);
		if ((string)($self['id'] ?? '') !== $id && (string)($publication['id'] ?? '') !== $id) {
			// The by-id route fell through to a list, or answered another row:
			// never show a publication the address does not name.
			return ['state' => 'not-found'];
		}

		return [
			'state'       => 'ok',
			'publication' => $publication,
			'documents'   => $this->documents(endpoint: $endpoint, id: $id),
			'themes'      => $this->themeNames(publication: $publication),
		];
	}//end publication()

	/**
	 * The documents of a publication; none when the list cannot be read.
	 *
	 * @param string $endpoint The widget's endpoint.
	 * @param string $id       The publication id.
	 *
	 * @return list<array{name: string, type: string, size: int, href: string}>
	 */
	private function documents(string $endpoint, string $id): array {
		$answer = $this->get(endpoint: $endpoint, suffix: '/'.rawurlencode($id).'/attachments', params: []);
		if ($answer['status'] !== 200 || is_array($answer['body']) === false) {
			return [];
		}

		$files = $answer['body'];
		if (array_is_list($files) === false) {
			$files = (array)($files['results'] ?? []);
		}

		$documents = [];
		foreach ($files as $file) {
			if (is_array($file) === false) {
				continue;
			}

			$name        = $this->firstText(candidates: [$file['title'] ?? '', $file['name'] ?? '', 'Document']);
			$documents[] = [
				'name' => $name,
				'type' => $this->fileType(file: $file, name: $name),
				'size' => max(0, (int)($file['size'] ?? 0)),
				'href' => $this->firstText(
					candidates: [$this->safeLink(value: (string)($file['downloadUrl'] ?? '')), $this->safeLink(value: (string)($file['accessUrl'] ?? ''))]
				),
			];
		}

		return $documents;
	}//end documents()

	/**
	 * The names of the publication's themes; a theme without a readable name
	 * is left out, never shown as its id.
	 *
	 * @param array<string, mixed> $publication The publication.
	 *
	 * @return list<string>
	 */
	private function themeNames(array $publication): array {
		$names = [];
		foreach ((array)($publication['themes'] ?? []) as $theme) {
			$themeId = $theme;
			if (is_array($theme) === true) {
				$themeId = ($theme['id'] ?? '');
			}

			$themeId = (string)$themeId;
			if (preg_match('/^[A-Za-z0-9._\-]{1,128}$/', $themeId) !== 1) {
				continue;
			}

			$answer = $this->get(endpoint: self::THEMES_ENDPOINT, suffix: '/'.rawurlencode($themeId), params: []);
			$body   = $answer['body'];
			if ($answer['status'] !== 200 || is_array($body) === false) {
				continue;
			}

			$name = $this->firstText(candidates: [$body['title'] ?? '', $body['name'] ?? '']);
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end themeNames()

	/**
	 * A search row as the plain page lists it.
	 *
	 * @param array<string, mixed> $row One row of the answer.
	 *
	 * @return array{id: string, title: string, date: string, summary: string}
	 */
	private function result(array $row): array {
		$self    = (array)($row['@self'] ?? []);
		$summary = $this->firstText(candidates: [$self['summary'] ?? '', $row['description'] ?? '']);

		return [
			'id'      => $this->firstText(candidates: [$self['id'] ?? '', $row['id'] ?? '']),
			'title'   => $this->firstText(candidates: [$row['name'] ?? '', $self['name'] ?? '', $self['title'] ?? '', $row['title'] ?? '', 'Zonder titel']),
			'date'    => $this->firstText(candidates: [$row['publicationDate'] ?? '', $self['published'] ?? '']),
			'summary' => mb_substr($summary, 0, 280),
		];
	}//end result()

	/**
	 * One anonymous GET on this instance.
	 *
	 * @param string                $endpoint The widget's endpoint.
	 * @param string                $suffix   Appended to the endpoint's path.
	 * @param array<string, string> $params   Query parameters.
	 *
	 * @return array{status: int, body: mixed} Status 0 when no call was made or nothing answered.
	 */
	private function get(string $endpoint, string $suffix, array $params): array {
		$none = ['status' => 0, 'body' => null];
		if ($this->isLocalPath(endpoint: $endpoint) === false || $this->appManager->isEnabledForUser('opencatalogi') === false) {
			return $none;
		}

		$path = rtrim($endpoint, '/').$suffix;
		if ($params !== []) {
			$path .= '?'.http_build_query($params);
		}

		try {
			$response = $this->loopback->request(
				method: 'GET',
				path: $path,
				options: [
					'timeout'         => self::TIMEOUT,
					'connect_timeout' => self::TIMEOUT,
					'http_errors'     => false,
					'headers'         => ['Accept' => 'application/json'],
				]
			);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: the plain page could not read publications', ['reason' => $e->getMessage()]);
			return $none;
		}

		return ['status' => $response->getStatusCode(), 'body' => json_decode((string)$response->getBody(), true)];
	}//end get()

	/**
	 * Whether an endpoint is a path on this instance: it starts with one
	 * slash, carries no scheme, host, query or fragment, and no `..` segment.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-publications-can-be-searched-without-javascript-req-shj-003
	 */
	public function isLocalPath(string $endpoint): bool {
		if (preg_match('#^/(?![/\\\\])[A-Za-z0-9._~/\-]*$#', $endpoint) !== 1) {
			return false;
		}

		return str_contains($endpoint, '/../') === false && str_ends_with($endpoint, '/..') === false;
	}//end isLocalPath()

	/**
	 * The first candidate that is a non-blank string, trimmed; '' for none.
	 *
	 * @param list<mixed> $candidates The candidates, in order.
	 *
	 * @return string
	 */
	private function firstText(array $candidates): string {
		foreach ($candidates as $candidate) {
			if (is_scalar($candidate) === true && trim((string)$candidate) !== '') {
				return trim((string)$candidate);
			}
		}

		return '';
	}//end firstText()

	/**
	 * A link a visitor can follow: http(s), or a path on this instance.
	 *
	 * @param string $value The candidate.
	 *
	 * @return string The link, or ''.
	 */
	private function safeLink(string $value): string {
		$value = trim($value);
		if (preg_match('#^https?://#i', $value) === 1 || preg_match('#^/(?!/)#', $value) === 1) {
			return $value;
		}

		return '';
	}//end safeLink()

	/**
	 * The file type a visitor recognises: the extension in capitals.
	 *
	 * @param array<string, mixed> $file The file.
	 * @param string               $name Its name.
	 *
	 * @return string
	 */
	private function fileType(array $file, string $name): string {
		$extension = trim((string)($file['extension'] ?? ''));
		if ($extension !== '') {
			return strtoupper($extension);
		}

		if (preg_match('/\.([A-Za-z0-9]{1,8})$/', $name, $match) === 1) {
			return strtoupper($match[1]);
		}

		return '';
	}//end fileType()
}//end class
