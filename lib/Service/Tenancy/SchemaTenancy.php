<?php

/**
 * Portaliq Schema Tenancy
 *
 * The one declaration of how each schema of the register is kept apart from
 * the schemas of other organisations.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Tenancy
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
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t01
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Tenancy;

/**
 * Every schema is scoped by `organisation`, by `portal`, by `subject`, through
 * its `parent`, or is `global` with a reason a reviewer can check. A census test
 * fails for a schema without a line here, so a new schema cannot ship without
 * the decision.
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t01
 */
final class SchemaTenancy {

	public const ORGANISATION = 'organisation';

	public const PORTAL = 'portal';

	public const SUBJECT = 'subject';

	public const PARENT = 'parent';

	public const GLOBAL = 'global';

	/**
	 * Schema slug to its scope. A `parent` names the schema it hangs on and the
	 * reference field that points there; a `global` says why.
	 *
	 * @var array<string, array{scope: string, parent?: string, via?: string, reason?: string}>
	 */
	public const MAP = [
		'portalAccount'           => ['scope' => self::ORGANISATION],
		'portalSession'           => ['scope' => self::ORGANISATION],
		'exampleDocument'         => ['scope' => self::ORGANISATION],
		'portalMessage'           => ['scope' => self::ORGANISATION],
		'portalSubmission'        => ['scope' => self::ORGANISATION],
		'portalNotification'      => ['scope' => self::ORGANISATION],
		'portal'                  => ['scope' => self::ORGANISATION],
		'portalCase'              => ['scope' => self::ORGANISATION],
		'portalMandate'           => ['scope' => self::ORGANISATION],
		'portalInvitation'        => ['scope' => self::ORGANISATION],
		'portalReferenceLink'     => ['scope' => self::ORGANISATION],
		'portalAccessRequest'     => ['scope' => self::ORGANISATION],
		'portalPoll'              => ['scope' => self::ORGANISATION],
		'portalAction'            => ['scope' => self::ORGANISATION],
		'portalContact'           => ['scope' => self::ORGANISATION],
		'portalPlan'              => ['scope' => self::ORGANISATION],
		'portalPlanTemplate'      => ['scope' => self::PORTAL],
		'menu'                    => ['scope' => self::PORTAL],
		'page'                    => ['scope' => self::PORTAL],
		'glossaryTerm'            => ['scope' => self::PORTAL],
		'media'                   => ['scope' => self::PORTAL],
		'portalTrafficEvent'      => ['scope' => self::PORTAL],
		'portalTrafficDaily'      => ['scope' => self::PORTAL],
		'portalTrafficRecording'  => ['scope' => self::PORTAL],
		'form'                    => ['scope' => self::PORTAL],
		'landingPageSubmission'   => ['scope' => self::PORTAL],
		'portalFormBinding'       => ['scope' => self::PORTAL],
		'portalIntakeSubmission'  => ['scope' => self::PORTAL],
		'portalMailTemplate'      => ['scope' => self::PORTAL],
		'portalMailLog'           => ['scope' => self::PORTAL],
		'accessibilityMeasurement' => ['scope' => self::PORTAL],
		'portalFaq'               => ['scope' => self::PORTAL],
		'portalFinder'            => ['scope' => self::PORTAL],
		'sharedBlock'             => ['scope' => self::ORGANISATION],
		'portalReport'            => ['scope' => self::PORTAL],
		'newsItem'                => ['scope' => self::PORTAL],
		'portalAvailabilityDaily' => ['scope' => self::PORTAL],
		'portalAvailabilityOutage' => ['scope' => self::PORTAL],
		'portalNotice'            => ['scope' => self::PORTAL],
		'portalPollResponse'      => ['scope' => self::SUBJECT],
		'pushSubscription'        => ['scope' => self::SUBJECT],
		'notificationQuietHours'  => ['scope' => self::SUBJECT],
		'pendingPush'             => ['scope' => self::SUBJECT],
		'portalDraft'             => ['scope' => self::SUBJECT],
		'portalReporterContact'   => ['scope' => self::PARENT, 'parent' => 'portalReport', 'via' => 'reportRef'],
		'portalReportMessage'     => ['scope' => self::PARENT, 'parent' => 'portalReport', 'via' => 'reportRef'],
		'portalRevealRequest'     => ['scope' => self::PARENT, 'parent' => 'portalReport', 'via' => 'reportRef'],
		'guardianMessage'         => ['scope' => self::PARENT, 'parent' => 'messageThread', 'via' => 'threadRef'],
		'eventRsvp'               => ['scope' => self::PARENT, 'parent' => 'schoolEvent', 'via' => 'eventRef'],
		'eventSignup'             => ['scope' => self::PARENT, 'parent' => 'schoolEvent', 'via' => 'eventRef'],
		'activitySignup'          => ['scope' => self::PARENT, 'parent' => 'activityOffer', 'via' => 'activityRef'],
		'activityAttendance'      => ['scope' => self::PARENT, 'parent' => 'activityOffer', 'via' => 'activityRef'],
		'portalOidcState' => [
			'scope'  => self::GLOBAL,
			'reason' => 'A single-use nonce for one sign-in round trip, consumed once and never listed.',
		],
		'portalPage' => [
			'scope'  => self::GLOBAL,
			'reason' => 'A contribution manifest read by the registry; it holds no resident data, and each audience reads its own.',
		],
		'portalCaseType' => [
			'scope'  => self::GLOBAL,
			'reason' => 'What a resident may change on a case type, declared by the case app and shared by every portal; it holds no resident data.',
		],
		'changeProposal' => [
			'scope'  => self::GLOBAL,
			'reason' => 'A queue read only by reviewers with write rights on the record, never through a portal read.',
		],
		'newsletter' => [
			'scope'  => self::GLOBAL,
			'reason' => 'Composed by staff from newsItem rows; has no portal field yet. A portal field is the follow-up.',
		],
		'guardianAudienceFixture' => [
			'scope'  => self::GLOBAL,
			'reason' => 'An interim fixture, never publicly readable and read only server-side.',
		],
		'groupStaffFixture' => [
			'scope'  => self::GLOBAL,
			'reason' => 'An interim fixture, never publicly readable and read only server-side.',
		],
		'schoolEvent' => [
			'scope'  => self::GLOBAL,
			'reason' => 'School data targeted by school reference; has no portal field yet. A portal field is the follow-up.',
		],
		'messageThread' => [
			'scope'  => self::GLOBAL,
			'reason' => 'Participation is decided by group fixtures; has no portal field yet. A portal field is the follow-up.',
		],
		'activityOffer' => [
			'scope'  => self::GLOBAL,
			'reason' => 'School data targeted by school reference; has no portal field yet. A portal field is the follow-up.',
		],
	];

	/**
	 * How a schema is kept apart, or null when it is not declared.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return string|null One of the scope constants.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t01
	 */
	public static function scopeOf(string $schema): ?string {
		return (self::MAP[$schema]['scope'] ?? null);
	}//end scopeOf()

	/**
	 * Whether a schema is declared scoped by organisation, which makes a missing
	 * tenant value a refusal.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return bool True for an organisation-scoped schema.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t04
	 */
	public static function isOrganisationScoped(string $schema): bool {
		return self::scopeOf(schema: $schema) === self::ORGANISATION;
	}//end isOrganisationScoped()

	/**
	 * Whether a schema is declared scoped by organisation, for a caller that holds an instance.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return bool True for an organisation-scoped schema.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t04
	 */
	public function declaresOrganisation(string $schema): bool {
		return self::isOrganisationScoped(schema: $schema);
	}//end declaresOrganisation()
}//end class
