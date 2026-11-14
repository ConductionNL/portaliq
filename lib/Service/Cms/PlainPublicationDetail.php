<?php

/**
 * The plain publication page: the publication, its facts and its documents.
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
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCP\IL10N;

/**
 * The view model of a `publicationDetail` widget on the plain page, for the
 * publication the route's trailing segment names.
 *
 * Title, summary, publication date, information category and themes by name,
 * and every document as a download link with its name, type and size. A
 * publication that does not exist and one an anonymous caller may not read are
 * both `not-found`, with the same words: the page answers 404 either way, and
 * nothing tells the two apart (REQ-SHJ-004).
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-a-publication-can-be-read-without-javascript-req-shj-004
 */
class PlainPublicationDetail {

	/**
	 * The themes endpoint the detail block reads when the page names none.
	 *
	 * @var string
	 */
	public const DEFAULT_THEMES_ENDPOINT = '/index.php/apps/opencatalogi/api/themes';

	/**
	 * Constructor.
	 *
	 * @param PlainPublicationReader $reader     The anonymous read.
	 * @param IL10N                  $l10n       The words.
	 * @param PlainDates             $dates      Dates as a reader writes them.
	 * @param PlainCategoryNames     $categories The information categories by name.
	 */
	public function __construct(
		private readonly PlainPublicationReader $reader,
		private readonly IL10N $l10n,
		private readonly PlainDates $dates,
		private readonly PlainCategoryNames $categories,
	) {
	}//end __construct()

	/**
	 * The detail block of one widget.
	 *
	 * @param array<string, mixed> $props   The widget's props.
	 * @param string               $id      The publication's id, from the route.
	 * @param string               $fullUrl The full page of this route.
	 * @param string               $locale  The document language.
	 *
	 * @return array<string, mixed> `state` is `ok`, `not-found` or `unavailable`.
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.3
	 */
	public function build(array $props, string $id, string $fullUrl, string $locale): array {
		$endpoint = $this->string(value: $props['endpoint'] ?? null, fallback: PlainPublicationSearch::DEFAULT_ENDPOINT);
		$block = ['kind' => 'publication', 'fullUrl' => $fullUrl, 'fullLabel' => $this->l10n->t('Open the full page')];

		if ($this->reader->isLocal(endpoint: $endpoint) === false) {
			return $block + $this->unavailable();
		}

		$read = $this->reader->publication(endpoint: $endpoint, id: $id);
		if ($read['state'] === 'not-found') {
			return $block + [
				'state' => 'not-found',
				'title' => $this->l10n->t('Publication not found'),
				'sentence' => $this->l10n->t('This publication does not exist, or it is not public.'),
			];
		}

		if ($read['state'] !== 'ok') {
			return $block + $this->unavailable();
		}

		$publication = (array)($read['publication'] ?? []);
		$themesEndpoint = $this->string(value: $props['themesEndpoint'] ?? null, fallback: self::DEFAULT_THEMES_ENDPOINT);

		return $block + [
			'state' => 'ok',
			'title' => $this->titleOf(publication: $publication),
			'summary' => $this->first(values: [$publication['summary'] ?? null, $publication['description'] ?? null]),
			'facts' => $this->facts(publication: $publication, themesEndpoint: $themesEndpoint, locale: $locale),
			'documentsLabel' => $this->l10n->t('Documents'),
			'documents' => $this->documents(documents: (array)($read['documents'] ?? []), locale: $locale),
		];
	}//end build()

	/**
	 * What a publication that could not be read shows.
	 *
	 * @return array<string, mixed>
	 */
	private function unavailable(): array {
		return [
			'state' => 'unavailable',
			'sentence' => $this->l10n->t('The publications cannot be shown here right now.'),
		];
	}//end unavailable()

	/**
	 * The title, as the detail block reads it.
	 *
	 * @param array<string, mixed> $publication The publication.
	 *
	 * @return string
	 */
	private function titleOf(array $publication): string {
		$self = (array)($publication['@self'] ?? []);
		$title = $this->first(values: [$publication['title'] ?? null, $publication['name'] ?? null, $self['name'] ?? null]);
		if ($title === '') {
			return $this->l10n->t('Untitled');
		}

		return $title;
	}//end titleOf()

	/**
	 * Date, category and themes, each only when it has a value.
	 *
	 * @param array<string, mixed> $publication    The publication.
	 * @param string               $themesEndpoint Where a theme's name is read.
	 * @param string               $locale         The document language.
	 *
	 * @return list<array{label: string, value: string}>
	 */
	private function facts(array $publication, string $themesEndpoint, string $locale): array {
		$facts = [];
		$date = $this->dates->readable(value: $this->first(values: [$publication['publicationDate'] ?? null]), locale: $locale);
		if ($date !== '') {
			$facts[] = ['label' => $this->l10n->t('Publication date'), 'value' => $date];
		}

		$category = $this->first(values: [$publication['wooCategory'] ?? null]);
		if ($category !== '') {
			$facts[] = ['label' => $this->l10n->t('Information category'), 'value' => $this->categories->name(code: $category, locale: $locale)];
		}

		$themes = $this->themeNames(themes: $publication['themes'] ?? null, endpoint: $themesEndpoint);
		if ($themes !== []) {
			$facts[] = ['label' => $this->l10n->t('Themes'), 'value' => implode(', ', $themes)];
		}

		return $facts;
	}//end facts()

	/**
	 * The names of a publication's themes. A theme given as an object names
	 * itself; one given as an id is read from the themes endpoint. A theme
	 * whose name cannot be read is left out, never shown as its id.
	 *
	 * @param mixed  $themes   The stored themes.
	 * @param string $endpoint The themes endpoint.
	 *
	 * @return list<string>
	 */
	private function themeNames(mixed $themes, string $endpoint): array {
		$names = [];
		foreach ((is_array($themes) === true ? $themes : []) as $theme) {
			$name = '';
			if (is_array($theme) === true) {
				$name = $this->first(values: [$theme['title'] ?? null, $theme['name'] ?? null]);
				if ($name === '' && is_string($theme['id'] ?? null) === true && $theme['id'] !== '') {
					$name = $this->reader->themeName(endpoint: $endpoint, id: $theme['id']);
				}
			} else if (is_string($theme) === true && $theme !== '') {
				$name = $this->reader->themeName(endpoint: $endpoint, id: $theme);
			}

			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end themeNames()

	/**
	 * The documents as links: name, type, readable size and address. A
	 * document without an address is listed without a link.
	 *
	 * @param list<array{name: string, type: string, size: int, href: string}> $documents The documents.
	 * @param string                                                         $locale    The document language.
	 *
	 * @return list<array{name: string, type: string, size: string, href: string}>
	 */
	private function documents(array $documents, string $locale): array {
		$shown = [];
		foreach ($documents as $document) {
			$shown[] = [
				'name' => (string)($document['name'] ?? ''),
				'type' => (string)($document['type'] ?? ''),
				'size' => $this->size(bytes: (int)($document['size'] ?? 0), locale: $locale),
				'href' => (string)($document['href'] ?? ''),
			];
		}

		return $shown;
	}//end documents()

	/**
	 * A size as a reader writes it: `120 kB`, `2,4 MB`; '' for none.
	 *
	 * @param int    $bytes  The size.
	 * @param string $locale The document language.
	 *
	 * @return string
	 */
	private function size(int $bytes, string $locale): string {
		if ($bytes <= 0) {
			return '';
		}

		$separator = ',';
		if (str_starts_with(strtolower($locale), 'en') === true) {
			$separator = '.';
		}

		if ($bytes < 1000) {
			return $bytes . ' B';
		}

		if ($bytes < 1000000) {
			return (string)round($bytes / 1000) . ' kB';
		}

		return str_replace('.', $separator, (string)round($bytes / 1000000, 1)) . ' MB';
	}//end size()

	/**
	 * The first non-blank string, trimmed.
	 *
	 * @param array<int, mixed> $values The candidates.
	 *
	 * @return string
	 */
	private function first(array $values): string {
		foreach ($values as $value) {
			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		return '';
	}//end first()

	/**
	 * A prop as a trimmed string, else the fallback.
	 *
	 * @param mixed  $value    The prop.
	 * @param string $fallback The default.
	 *
	 * @return string
	 */
	private function string(mixed $value, string $fallback): string {
		if (is_string($value) === true && trim($value) !== '') {
			return trim($value);
		}

		return $fallback;
	}//end string()
}//end class
