<?php

/**
 * Portaliq contributed pages
 *
 * The pages the installed apps contribute, per audience, as an administrator
 * or a clerk chooses among them: read from the aggregate with a synthetic
 * subject of each audience at the highest trust, so a page that needs a
 * stronger sign-in is still listed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;

/**
 * Lists the pages the installed apps contribute, per audience.
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
 */
class ContributedPages {
	/**
	 * Constructor.
	 *
	 * @param PortalContributionRegistryInterface $registry The contribution registry.
	 */
	public function __construct(
		private readonly PortalContributionRegistryInterface $registry,
	) {
	}//end __construct()

	/**
	 * Every audience the installed apps serve, each with the pages they
	 * contribute for it, in aggregate order.
	 *
	 * @return array<string, list<array{page: string, app: string, pageId: string, label: string, appLabel: string, menu: bool}>>
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
	 */
	public function byAudience(): array {
		$out = [];
		foreach ($this->registry->servedAudiences() as $audience) {
			if (is_string($audience) === false || $audience === '') {
				continue;
			}

			$out[$audience] = $this->forAudience(audience: $audience);
		}

		return $out;
	}//end byAudience()

	/**
	 * The pages the installed apps contribute for one audience.
	 *
	 * @param string $audience The audience.
	 *
	 * @return list<array{page: string, app: string, pageId: string, label: string, appLabel: string, menu: bool}>
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
	 */
	public function forAudience(string $audience): array {
		if ($audience === '') {
			return [];
		}

		$aggregate = $this->registry->aggregateFor(
			subject: ['audience' => $audience, 'trust' => 'high', 'subjectRef' => '', 'organisation' => '']
		);

		$pages = [];
		foreach ((array)($aggregate['contributions'] ?? []) as $contribution) {
			if (is_array($contribution) === true) {
				$pages = array_merge($pages, $this->pagesOf(contribution: $contribution));
			}
		}

		return $pages;
	}//end forAudience()

	/**
	 * The pages of one contribution, keyed as `<app>:<pageId>`; `menu` is
	 * false for a page that keeps its route but stays out of the menu.
	 *
	 * @param array<string, mixed> $contribution The normalised contribution.
	 *
	 * @return list<array{page: string, app: string, pageId: string, label: string, appLabel: string, menu: bool}>
	 */
	private function pagesOf(array $contribution): array {
		$app      = (string)($contribution['app'] ?? '');
		$appLabel = $this->text(value: ($contribution['label'] ?? null), fallback: $app);
		$pages    = [];
		foreach ((array)($contribution['pages'] ?? []) as $page) {
			$pageId = '';
			if (is_array($page) === true) {
				$pageId = (string)($page['id'] ?? '');
			}

			if ($app === '' || $pageId === '') {
				continue;
			}

			$pages[] = [
				'page'     => $app . ':' . $pageId,
				'app'      => $app,
				'pageId'   => $pageId,
				'label'    => $this->text(value: ($page['label'] ?? $page['title'] ?? null), fallback: $pageId),
				'appLabel' => $appLabel,
				'menu'     => (($page['menu'] ?? true) !== false),
			];
		}

		return $pages;
	}//end pagesOf()

	/**
	 * A trimmed string, or the fallback when the value is not a non-empty string.
	 *
	 * @param mixed  $value    The value.
	 * @param string $fallback The fallback.
	 *
	 * @return string
	 */
	private function text(mixed $value, string $fallback): string {
		if (is_string($value) === true && trim($value) !== '') {
			return trim($value);
		}

		return $fallback;
	}//end text()
}//end class
