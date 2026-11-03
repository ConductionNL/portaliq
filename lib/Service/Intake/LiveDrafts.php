<?php
/**
 * Portaliq Live Drafts
 *
 * Every form a resident saved and can still finish: one entry per action
 * that declares a draft and can keep one, with its live draft
 * (form-save-and-resume-dialogs, board MijnZaken "Onvoltooid formulier").
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/form-save-and-resume-dialogs/specs/site-forms/spec.md#requirement-the-own-area-lists-unfinished-forms-req-fsr-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Contribution\PortalContributionRegistry;

/**
 * Lists a subject's live drafts across the actions it may use.
 *
 * @spec openspec/changes/form-save-and-resume-dialogs/specs/site-forms/spec.md#requirement-the-own-area-lists-unfinished-forms-req-fsr-003
 */
class LiveDrafts {
	/**
	 * Constructor.
	 *
	 * @param PortalContributionRegistry $registry The subject's aggregate of actions.
	 * @param ActionDraft                $actions  Whether an action keeps drafts, and its live draft.
	 */
	public function __construct(
		private readonly PortalContributionRegistry $registry,
		private readonly ActionDraft $actions,
	) {
	}//end __construct()

	/**
	 * The subject's live drafts, one per action that declares a draft and can
	 * keep one: its app, action, label, last save and expiry. A draft past its
	 * retention is discarded on the way, as find() does (REQ-FSR-003).
	 *
	 * @param array<string, mixed> $subject The resolved portal subject.
	 *
	 * @return array<int, array{app: string, action: string, label: string, updatedAt: string, expiresAt: string}>
	 *
	 * @spec openspec/changes/form-save-and-resume-dialogs/specs/site-forms/spec.md#requirement-the-own-area-lists-unfinished-forms-req-fsr-003
	 */
	public function forSubject(array $subject): array {
		$subjectRef = (string)($subject['subjectRef'] ?? '');
		$live       = [];
		foreach (($this->registry->aggregateFor($subject)['contributions'] ?? []) as $contribution) {
			if (is_array($contribution) === false) {
				continue;
			}

			$appId = (string)($contribution['app'] ?? '');
			foreach ((array)($contribution['actions'] ?? []) as $action) {
				if (is_array($action) === false
					|| is_array($action['draft'] ?? null) === false
					|| $this->actions->isDraftable(action: $action, subject: $subject) === false
				) {
					continue;
				}

				$draft = $this->actions->find(action: $action, subjectRef: $subjectRef);
				if ($draft === null) {
					continue;
				}

				$live[] = [
					'app'       => $appId,
					'action'    => (string)($action['id'] ?? ''),
					'label'     => (string)($action['label'] ?? ''),
					'updatedAt' => (string)($draft['updatedAt'] ?? ''),
					'expiresAt' => (string)$draft['expiresAt'],
				];
			}//end foreach
		}//end foreach

		return $live;
	}//end forSubject()
}//end class
