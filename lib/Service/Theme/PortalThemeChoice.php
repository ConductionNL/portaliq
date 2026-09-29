<?php

/**
 * Which theme one portal wears, chosen from the sets the theme app offers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Theme
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Theme;

use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalThemeResolver;

/**
 * Lists the adoptable sets with their contrast verdict, and saves a choice.
 *
 * A set is adoptable only when the resolver would render it: listed in the
 * theme app's catalogue and with a token file on disk. `portal.theme` stops
 * being free text an administrator has to spell right.
 */
class PortalThemeChoice {

	/**
	 * Portaliq's register.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The portal schema.
	 */
	private const SCHEMA = 'portal';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader  $reader   Reads the portal.
	 * @param PortalObjectWriter  $writer   Saves the choice.
	 * @param PortalThemeResolver $resolver The theme app's catalogue and tokens.
	 * @param PortalThemeContrast $contrast The contrast verdict per set.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly PortalThemeResolver $resolver,
		private readonly PortalThemeContrast $contrast,
	) {
	}//end __construct()

	/**
	 * The portal with this slug, or null.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
	 */
	public function portalBySlug(string $slug): ?array {
		if ($slug === '') {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'slug',
			subjectRef: $slug,
			organisation: '',
			limit: 2
		);
		foreach ($rows as $row) {
			if (is_array($row) === true && ($row['slug'] ?? null) === $slug) {
				return $row;
			}
		}

		return null;
	}//end portalBySlug()

	/**
	 * The portal's theme and every set it can adopt, each with its verdict.
	 *
	 * @param array<string, mixed> $portal The portal.
	 *
	 * @return array{current: string, currentResolves: bool, sets: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
	 */
	public function listFor(array $portal): array {
		$current = (string)($portal['theme'] ?? '');
		$sets = [];
		foreach ($this->resolver->catalogue() as $entry) {
			$id = (string)$entry['id'];
			if ($this->resolver->stylesheetFor(theme: $id) === null) {
				continue;
			}

			$sets[] = [
				'id' => $id,
				'name' => (string)($entry['name'] ?? $id),
				'verdict' => $this->contrast->evaluate(tokens: $this->resolver->tokenValuesFor(theme: $id)),
			];
		}

		return [
			'current' => $current,
			'currentResolves' => ($current !== '' && $this->resolver->stylesheetFor(theme: $current) !== null),
			'sets' => $sets,
		];
	}//end listFor()

	/**
	 * Save a theme for the portal.
	 *
	 * A set the resolver would not render is refused. A set whose tokens fail
	 * AA on a portal surface is refused unless the administrator confirms,
	 * and the refusal carries the findings so they can see why.
	 *
	 * @param array<string, mixed> $portal         The portal.
	 * @param string               $theme          The set id.
	 * @param bool                 $acceptFindings Whether the administrator confirmed failing contrast.
	 *
	 * @return array<string, mixed> `{portal}` or `{error, verdict?}`.
	 *
	 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
	 */
	public function choose(array $portal, string $theme, bool $acceptFindings = false): array {
		if ($this->resolver->stylesheetFor(theme: $theme) === null) {
			return ['error' => 'unknown_theme'];
		}

		$verdict = $this->contrast->evaluate(tokens: $this->resolver->tokenValuesFor(theme: $theme));
		if ($verdict['findings'] !== [] && $acceptFindings === false) {
			return ['error' => 'contrast', 'verdict' => $verdict];
		}

		$saved = $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'slug',
			subjectRef: (string)($portal['slug'] ?? ''),
			organisation: '',
			id: (string)($portal['id'] ?? $portal['uuid'] ?? ($portal['@self']['id'] ?? '')),
			data: ['theme' => $theme]
		);
		if ($saved === null) {
			return ['error' => 'save_failed'];
		}

		return ['portal' => $saved, 'verdict' => $verdict];
	}//end choose()
}//end class
