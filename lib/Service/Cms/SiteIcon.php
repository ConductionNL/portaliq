<?php

/**
 * Portaliq site icon
 *
 * The tab icon of a portal's site: its favicon, then its logo, then the
 * theme's icon, then portaliq's own mark.
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

use OCA\Portaliq\AppInfo\Application;
use OCP\IURLGenerator;

/**
 * Resolves the one `<link rel="icon">` of a site page.
 *
 * A media reference resolves through MediaReferences, which reads only the
 * published items of the portal's own library and answers the content API's
 * public media address, so the icon loads without a session. A reference that
 * does not resolve falls through to the next candidate rather than to a
 * broken link.
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-site-head-and-the-hero-use-the-portals-images-req-pia-002
 */
class SiteIcon {

	/**
	 * Constructor.
	 *
	 * @param MediaReferences $media Resolves media:<id> to a public address.
	 * @param IURLGenerator   $urls  Builds the address of portaliq's own mark.
	 */
	public function __construct(
		private readonly MediaReferences $media,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * The icon's address.
	 *
	 * @param array<string, mixed>|null $portal    The serving portal, or null.
	 * @param string                    $themeIcon The theme's icon address, or ''.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-site-head-and-the-hero-use-the-portals-images-req-pia-002
	 */
	public function url(?array $portal, string $themeIcon): string {
		$slug = (string)($portal['slug'] ?? '');
		foreach (['favicon', 'logo'] as $field) {
			$found = $this->image(slug: $slug, value: ($portal[$field] ?? null));
			if ($found !== '') {
				return $found;
			}
		}

		if ($themeIcon !== '') {
			return $themeIcon;
		}

		return $this->urls->linkTo(Application::APP_ID, 'img/app.svg');
	}//end url()

	/**
	 * One stored image as an address: a resolved media reference, or a web or
	 * site-relative address as it is; '' for anything else.
	 *
	 * @param string $slug  The portal slug.
	 * @param mixed  $value The stored value.
	 *
	 * @return string
	 */
	private function image(string $slug, mixed $value): string {
		if (is_string($value) === false || trim($value) === '') {
			return '';
		}

		if (MediaReferences::isReference(value: $value) === true) {
			if ($slug === '') {
				return '';
			}

			return $this->media->image(portal: $slug, value: $value);
		}

		if (preg_match('#^(https?://|/(?!/))#i', $value) === 1) {
			return $value;
		}

		return '';
	}//end image()
}//end class
