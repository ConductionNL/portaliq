<?php

/**
 * Portaliq Withdrawn Publication
 *
 * Whether a site path is the page of a withdrawn publication that the portal
 * chose to say was withdrawn (publication-error-reports-and-withheld-notices
 * REQ-PER-003, REQ-PER-004). The site shell then answers 410 instead of 200,
 * so a search engine drops the address and a visitor's browser is told the
 * truth. With the portal's notices off, the page stays a plain not-found.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Publication
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
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#3-withheld-notices
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Publication;

use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCP\IRequest;
use Throwable;

/**
 * Tells the site shell that a path is a withdrawn publication's page.
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#3-withheld-notices
 */
class WithdrawnPublication {
	/**
	 * The portal property that switches the notices on.
	 */
	public const SWITCH = 'showWithheldNotices';

	/**
	 * The site route every publication's page lives under: `/publicatie/<id>`.
	 */
	public const ROUTE = 'publicatie';

	/**
	 * Constructor.
	 *
	 * @param PublicPublicationRead        $publications Reads the publication anonymously.
	 * @param PortalResolverInterface|null $portals      Resolves the serving portal for a request.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PublicPublicationRead $publications,
		private readonly ?PortalResolverInterface $portals = null,
	) {
	}//end __construct()

	/**
	 * The same answer for a site request: the portal it resolves to, by the
	 * slug it names or else by its host. A resolver failure is no portal.
	 *
	 * @param IRequest    $request The site request.
	 * @param string|null $slug    The slug it names, or null.
	 * @param string      $path    The path under the site route.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-withdrawn-link-says-it-was-withdrawn-and-why-req-per-004
	 */
	public function isWithdrawnRequest(IRequest $request, ?string $slug, string $path): bool {
		if ($this->portals === null) {
			return false;
		}

		try {
			$portal = $this->portals->resolve(request: $request, portalSlug: $slug);
		} catch (Throwable) {
			return false;
		}

		return $this->isWithdrawnPage(portal: $portal, path: $path);
	}//end isWithdrawnRequest()

	/**
	 * Whether the path is `/publicatie/<id>` for a publication the public read
	 * answers 410 for, on a portal with its notices switched on. Only a literal
	 * true switches them on; any other path is not asked about at all.
	 *
	 * @param array<string, mixed>|null $portal The serving portal.
	 * @param string                    $path   The path under the site route.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-withdrawn-link-says-it-was-withdrawn-and-why-req-per-004
	 */
	public function isWithdrawnPage(?array $portal, string $path): bool {
		if ($portal === null || ($portal[self::SWITCH] ?? false) !== true) {
			return false;
		}

		if (preg_match('#^/?' . self::ROUTE . '/([^/]+)/?$#', $path, $match) !== 1) {
			return false;
		}

		return $this->publications->read(id: rawurldecode($match[1]))['status'] === PublicPublicationRead::GONE;
	}//end isWithdrawnPage()
}//end class
