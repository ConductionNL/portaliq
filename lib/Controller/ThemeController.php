<?php

/**
 * Portaliq Theme Controller
 *
 * The themes a portal can adopt, and whether each one meets AA on the surfaces
 * this renderer actually paints.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/changes/nldesign-theme-integration/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Service\PortalThemeContrast;
use OCA\Portaliq\Service\PortalThemeResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * The adoptable theme catalogue, with a contrast verdict per set.
 *
 * NOT PUBLIC. The catalogue includes admin-uploaded custom sets, and the theme
 * app's own endpoint is `#[NoAdminRequired]` for exactly that reason — its
 * docblock says exposing them anonymously "would be a new
 * information-disclosure surface with no consumer need". This endpoint has the
 * same posture; the public renderer reads what it needs from disk instead.
 *
 * `#[NoAdminRequired]` rather than admin-only: the people who choose a portal's
 * theme are not always the instance's administrators.
 *
 * @spec openspec/changes/nldesign-theme-integration/tasks.md
 */
class ThemeController extends Controller {


	/**
	 * @param string              $appName  The app id.
	 * @param IRequest            $request  The request.
	 * @param PortalThemeResolver $resolver Reads the catalogue and its tokens.
	 * @param PortalThemeContrast $contrast Evaluates a set against the painted surfaces.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalThemeResolver $resolver,
		private readonly PortalThemeContrast $contrast,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()


	/**
	 * Every theme a portal can adopt, with its accessibility verdict.
	 *
	 * THE VERDICT TRAVELS WITH THE CHOICE (tasks 3.1, 3.2). A theme picker that
	 * lists names alone makes accessibility something discovered in a review,
	 * weeks after a portal went live wearing it; listing the measured ratio
	 * beside the name makes it something the person choosing can act on in the
	 * moment they are choosing.
	 *
	 * A SET IS NEVER REFUSED HERE, and that is deliberate. The failing token is
	 * NAMED with its ratio and the surface it fails on, so an operator can see
	 * exactly what is wrong — but blocking adoption would mean this app
	 * deciding that a municipality may not use its own brand, which is not its
	 * decision to make and would be worked around by editing the data
	 * directly. `evaluated: false` is reported distinctly from `passes: false`:
	 * "nothing checked this" and "this fails" are different facts.
	 *
	 * @return DataResponse `{sets: [{id, label, contrast}]}`.
	 *
	 * @spec openspec/changes/nldesign-theme-integration/tasks.md
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		$sets = [];

		foreach ($this->resolver->catalogue() as $entry) {
			$id = (string)($entry['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$sets[] = [
				'id' => $id,
				'label' => (string)($entry['label'] ?? $entry['name'] ?? $id),
				'extends' => (string)($entry['extends'] ?? ''),
				'contrast' => $this->contrast->evaluate(
					tokens: $this->resolver->tokenValuesFor($id)
				),
			];
		}

		return new DataResponse(data: ['sets' => $sets]);
	}//end index()


}//end class
