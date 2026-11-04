<?php

/**
 * Portaliq Cta Block Normaliser (site-mijn-omgeving-components)
 *
 * A `cta` block names exactly one target: an action of its contribution (as
 * before), a page of its contribution, or a route inside the portal. On a
 * record page it may open that target for the open record (`withRecord`),
 * and its label may hold `{title}`, the record's title as plain text.
 *
 * SECURITY: a route must start with a single `/` and carry no scheme, host
 * or `//`, so a contribution can never send a resident off the portal from a
 * tile that looks like its own. A cta naming none or more than one target is
 * dropped.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cta-block-may-open-a-page-or-a-site-route-for-the-open-record-with-the-record-in-its-label-req-smo-024
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Normalises a `cta` block.
 *
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cta-block-may-open-a-page-or-a-site-route-for-the-open-record-with-the-record-in-its-label-req-smo-024
 */
class CtaBlockNormaliser {
	/**
	 * The longest label a cta may carry.
	 */
	private const MAX_LABEL_LENGTH = 120;

	/**
	 * A `cta` block, or null when it names no single valid target or no label.
	 *
	 * @param array<string, mixed> $block     The declared block.
	 * @param array<int, string>   $actionIds The contribution's action ids.
	 * @param array<int, string>   $pageIds   The contribution's page ids.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cta-block-may-open-a-page-or-a-site-route-for-the-open-record-with-the-record-in-its-label-req-smo-024
	 */
	public function normalise(array $block, array $actionIds, array $pageIds): ?array {
		$label = ($block['label'] ?? null);
		if (is_string($label) === false || trim($label) === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
			return null;
		}

		$declared = array_filter(
			['action' => ($block['action'] ?? null), 'page' => ($block['page'] ?? null), 'route' => ($block['route'] ?? null)],
			static fn ($value): bool => $value !== null
		);
		if (count($declared) !== 1) {
			return null;
		}

		$key = (string)array_key_first($declared);
		if ($this->resolves(key: $key, value: $declared[$key], actionIds: $actionIds, pageIds: $pageIds) === false) {
			return null;
		}

		return $this->withOptions(entry: ['type' => 'cta', $key => $declared[$key], 'label' => trim($label)], block: $block);
	}//end normalise()

	/**
	 * A cta with its optional keys: `withRecord`, and `labelWhenEmpty`, the
	 * label shown when a field the label names is empty on the open record
	 * ("Bericht aan {teacherName}" without a teacher reads "Bericht sturen").
	 *
	 * @param array<string, mixed> $entry The cta so far.
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/guardian-pages-follow-the-wilgenboom-boards/specs/portal-contribution-contract/spec.md#requirement-a-cta-label-may-name-any-field-of-the-open-record
	 */
	private function withOptions(array $entry, array $block): array {
		if (($block['withRecord'] ?? null) === true) {
			$entry['withRecord'] = true;
		}

		$fallback = ($block['labelWhenEmpty'] ?? null);
		if (is_string($fallback) === true && trim($fallback) !== '' && mb_strlen($fallback) <= self::MAX_LABEL_LENGTH) {
			$entry['labelWhenEmpty'] = trim($fallback);
		}

		return $entry;
	}//end withOptions()

	/**
	 * Whether a declared target resolves: a known action or page, or a route
	 * inside the portal.
	 *
	 * @param string             $key       `action`, `page` or `route`.
	 * @param mixed              $value     The declared target.
	 * @param array<int, string> $actionIds The contribution's action ids.
	 * @param array<int, string> $pageIds   The contribution's page ids.
	 *
	 * @return bool
	 */
	private function resolves(string $key, mixed $value, array $actionIds, array $pageIds): bool {
		if ($key === 'action') {
			return in_array($value, $actionIds, true);
		}

		if ($key === 'page') {
			return in_array($value, $pageIds, true);
		}

		return is_string($value) === true
			&& preg_match('#^/(?!/)[A-Za-z0-9._~\-/%]*$#', $value) === 1
			&& str_contains($value, '//') === false;
	}//end resolves()
}//end class
