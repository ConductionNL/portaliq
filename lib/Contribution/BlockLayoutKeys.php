<?php

/**
 * Portaliq Block Layout Keys (mijn-overview-follows-the-boards)
 *
 * The keys any block of a contributed page may carry to stand where the
 * school overviews draw it:
 * - `column`: `main` or `side`; consecutive blocks with a column form a band
 *   of two columns, a block without one spans the page;
 * - `frame`: `line` (a bordered card) or `tinted` (a grey ground); `true`
 *   reads as `line`;
 * - `more`: `{label, page|route, placement}`, the "Alle cijfers" link of the
 *   block, at the end of its heading row (`heading`, the default) or under it
 *   (`end`). The page must be one of the contribution's, the route a path
 *   inside the site; otherwise the link is dropped.
 * A key that does not fit is dropped; the block keeps its place in the flow.
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
 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-stand-in-a-column-and-in-a-frame
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Normalises the column, frame and "more" link of any block.
 *
 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-stand-in-a-column-and-in-a-frame
 */
class BlockLayoutKeys {

	/**
	 * The columns a block may stand in.
	 */
	private const COLUMNS = ['main', 'side'];

	/**
	 * The frames a block may have.
	 */
	private const FRAMES = ['line', 'tinted'];

	/**
	 * The longest link label.
	 */
	private const MAX_LABEL = 60;

	/**
	 * The layout keys of one block.
	 *
	 * @param array<string, mixed> $block   The declared block.
	 * @param array<int, string>   $pageIds The contribution's page ids.
	 *
	 * @return array<string, mixed> `{column?, frame?, more?}`.
	 *
	 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-stand-in-a-column-and-in-a-frame
	 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-carry-a-link-to-all-of-it
	 */
	public function keys(array $block, array $pageIds): array {
		$out = [];
		if (in_array(($block['column'] ?? null), self::COLUMNS, true) === true) {
			$out['column'] = $block['column'];
		}

		$frame = ($block['frame'] ?? null);
		if ($frame === true) {
			$frame = 'line';
		}

		if (in_array($frame, self::FRAMES, true) === true) {
			$out['frame'] = $frame;
		}

		$more = $this->more(declared: ($block['more'] ?? null), pageIds: $pageIds);
		if ($more !== null) {
			$out['more'] = $more;
		}

		return $out;
	}//end keys()

	/**
	 * A block's link to all of it, or null when it does not resolve.
	 *
	 * @param mixed              $declared The declared link.
	 * @param array<int, string> $pageIds  The contribution's page ids.
	 *
	 * @return array<string, string>|null `{label, page|route, placement?}`.
	 *
	 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-carry-a-link-to-all-of-it
	 */
	private function more(mixed $declared, array $pageIds): ?array {
		if (is_array($declared) === false) {
			return null;
		}

		$label = ($declared['label'] ?? null);
		if (is_string($label) === false || trim($label) === '' || mb_strlen(trim($label)) > self::MAX_LABEL) {
			return null;
		}

		// The page or route resolves the way a cta's does; an action is no link.
		$cta = (new CtaBlockNormaliser())->normalise(
			block: ['label' => $label, 'page' => ($declared['page'] ?? null), 'route' => ($declared['route'] ?? null)],
			actionIds: [],
			pageIds: $pageIds
		);
		if ($cta === null) {
			return null;
		}

		unset($cta['type']);
		if (($declared['placement'] ?? null) === 'end') {
			$cta['placement'] = 'end';
		}

		return $cta;
	}//end more()
}//end class
