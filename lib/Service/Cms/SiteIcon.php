<?php

/**
 * Portaliq site icon
 *
 * Chooses the tab icon of a portal's site: the portal's favicon, then its
 * logo, then the theme's icon, then portaliq's own mark.
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
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-site-head-and-the-hero-use-the-portals-images-req-pia-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * The tab icon of a portal's site.
 *
 * A media reference resolves only to a published item of the portal's own
 * library, through the public content API, so the address answers without a
 * session. A reference that does not resolve is skipped, never emitted.
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-site-head-and-the-hero-use-the-portals-images-req-pia-002
 */
class SiteIcon {

	/**
	 * Constructor.
	 *
	 * @param MediaReferences $media Resolves media:<id> to the item's public address.
	 */
	public function __construct(
		private readonly MediaReferences $media,
	) {
	}//end __construct()

	/**
	 * The portal's own icon: its favicon, else its logo, else ''.
	 *
	 * @param array<string, mixed>|null $portal The serving portal, or null.
	 *
	 * @return string The public address, or '' when the portal sets none that resolves.
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-site-head-and-the-hero-use-the-portals-images-req-pia-002
	 */
	public function forPortal(?array $portal): string {
		if ($portal === null) {
			return '';
		}

		$slug = (string) ($portal['slug'] ?? '');
		foreach (['favicon', 'logo'] as $field) {
			$address = $this->address(portal: $slug, value: ($portal[$field] ?? null));
			if ($address !== '') {
				return $address;
			}
		}

		return '';
	}//end forPortal()

	/**
	 * The first candidate that is set: the portal's icon, the theme's icon, the own mark.
	 *
	 * @param string ...$candidates The addresses in order of preference.
	 *
	 * @return string The first non-empty address, or ''.
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-site-head-and-the-hero-use-the-portals-images-req-pia-002
	 */
	public static function first(string ...$candidates): string {
		foreach ($candidates as $candidate) {
			if ($candidate !== '') {
				return $candidate;
			}
		}

		return '';
	}//end first()

	/**
	 * The address a stored image value stands for.
	 *
	 * A media reference becomes the item's public address, or '' when it does
	 * not resolve. An http(s) address or a root-relative path is kept as it is.
	 * Anything else is ''.
	 *
	 * @param string $portal The portal slug.
	 * @param mixed  $value  The stored value.
	 *
	 * @return string
	 */
	private function address(string $portal, mixed $value): string {
		if (is_string($value) === false || trim($value) === '') {
			return '';
		}

		$value = trim($value);
		if (MediaReferences::isReference(value: $value) === true) {
			return $this->media->image(portal: $portal, value: $value);
		}

		if (preg_match('#^(https?://|/(?!/))#i', $value) === 1) {
			return $value;
		}

		return '';
	}//end address()
}//end class
