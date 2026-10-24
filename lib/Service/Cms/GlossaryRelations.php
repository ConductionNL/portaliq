<?php

/**
 * Portaliq glossary relations: a term relates only to terms on its own portal
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-cms-content-model/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * Decides whether a glossary term's relations stay on its portal.
 *
 * @spec openspec/changes/portal-cms-content-model/tasks.md#task-3
 */
class GlossaryRelations {

	/**
	 * Why a term's relations are refused, or null when each one names a term of the same portal.
	 *
	 * @param string                           $portal     The portal the written term belongs to.
	 * @param array<int, mixed>                $relations  The ids the term relates to.
	 * @param array<int, array<string, mixed>> $portalTerms Every term stored on that portal.
	 *
	 * @return string[] The relation ids that do not resolve to a term of this portal.
	 *
	 * @spec openspec/changes/portal-cms-content-model/tasks.md#task-3
	 */
	public function foreign(string $portal, array $relations, array $portalTerms): array {
		$known = $this->knownIds(portal: $portal, portalTerms: $portalTerms);
		$foreign = [];
		foreach ($relations as $relation) {
			if (is_string($relation) === false || isset($known[$relation]) === false) {
				$foreign[] = $this->label(relation: $relation);
			}
		}

		return $foreign;
	}//end foreign()

	/**
	 * The ids of the terms of this portal, as keys.
	 *
	 * @param string                           $portal      The portal slug.
	 * @param array<int, array<string, mixed>> $portalTerms Every term stored on that portal.
	 *
	 * @return array<string, bool>
	 */
	private function knownIds(string $portal, array $portalTerms): array {
		$known = [];
		foreach ($portalTerms as $term) {
			if ((string)($term['portal'] ?? $portal) !== $portal) {
				continue;
			}

			$self = [];
			if (is_array($term['@self'] ?? null) === true) {
				$self = $term['@self'];
			}

			foreach ([$term['id'] ?? null, $term['uuid'] ?? null, $self['id'] ?? null, $self['uuid'] ?? null] as $candidate) {
				if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
					$known[(string)$candidate] = true;
				}
			}
		}

		return $known;
	}//end knownIds()

	/**
	 * The text to show for a relation that does not resolve.
	 *
	 * @param mixed $relation The relation as written.
	 *
	 * @return string
	 */
	private function label(mixed $relation): string {
		if (is_scalar($relation) === true) {
			return (string)$relation;
		}

		return '';
	}//end label()
}//end class
