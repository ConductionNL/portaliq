<?php

/**
 * Bindings of the Kernel contracts to their Service implementations
 *
 * @category  Service
 * @package   OCA\Portaliq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract wiring, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Kernel\Contracts\AccessibilityFramingInterface;
use OCA\Portaliq\Kernel\Contracts\AccessibilityMeasurementsInterface;
use OCA\Portaliq\Kernel\Contracts\AccessibilityRunInterface;
use OCA\Portaliq\Kernel\Contracts\AccessibilityStatementInterface;
use OCA\Portaliq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Portaliq\Kernel\Contracts\ActionDraftInterface;
use OCA\Portaliq\Kernel\Contracts\ActionReminderServiceInterface;
use OCA\Portaliq\Kernel\Contracts\ActivityAttendanceServiceInterface;
use OCA\Portaliq\Kernel\Contracts\ActivityContributionServiceInterface;
use OCA\Portaliq\Kernel\Contracts\ActivityDraftInterface;
use OCA\Portaliq\Kernel\Contracts\ActivityFeedReaderInterface;
use OCA\Portaliq\Kernel\Contracts\ActivityPlacesInterface;
use OCA\Portaliq\Kernel\Contracts\ActivityRosterInterface;
use OCA\Portaliq\Kernel\Contracts\ActivitySignupServiceInterface;
use OCA\Portaliq\Kernel\Contracts\ActivityStoreInterface;
use OCA\Portaliq\Kernel\Contracts\AdminMenuAccessInterface;
use OCA\Portaliq\Kernel\Contracts\AuditTrailServiceInterface;
use OCA\Portaliq\Kernel\Contracts\AvailabilityProbeInterface;
use OCA\Portaliq\Kernel\Contracts\AvailabilityReportInterface;
use OCA\Portaliq\Kernel\Contracts\AvailabilityRollupInterface;
use OCA\Portaliq\Kernel\Contracts\AvailabilityStoreInterface;
use OCA\Portaliq\Kernel\Contracts\BranchChoiceInterface;
use OCA\Portaliq\Kernel\Contracts\CaseRowMarkerInterface;
use OCA\Portaliq\Kernel\Contracts\CaseTypeColumnSettingsInterface;
use OCA\Portaliq\Kernel\Contracts\CaseTypeNamesInterface;
use OCA\Portaliq\Kernel\Contracts\CaseTypeReaderInterface;
use OCA\Portaliq\Kernel\Contracts\CaseTypeVisibilityInterface;
use OCA\Portaliq\Kernel\Contracts\CitizenCaseDocumentsInterface;
use OCA\Portaliq\Kernel\Contracts\CitizenCaseProjectionInterface;
use OCA\Portaliq\Kernel\Contracts\CitizenWritableSetResolverInterface;
use OCA\Portaliq\Kernel\Contracts\CitizenWriteRecorderInterface;
use OCA\Portaliq\Kernel\Contracts\CitizenWriteThrottleInterface;
use OCA\Portaliq\Kernel\Contracts\CmsPublishValidatorInterface;
use OCA\Portaliq\Kernel\Contracts\CmsReaderInterface;
use OCA\Portaliq\Kernel\Contracts\CollectionDocumentsInterface;
use OCA\Portaliq\Kernel\Contracts\ColumnOverridesInterface;
use OCA\Portaliq\Kernel\Contracts\ContactAddressValuesInterface;
use OCA\Portaliq\Kernel\Contracts\ContactConfirmationMailerInterface;
use OCA\Portaliq\Kernel\Contracts\DemoDataServiceInterface;
use OCA\Portaliq\Kernel\Contracts\DestinationDraftInterface;
use OCA\Portaliq\Kernel\Contracts\DestinationIntakeInterface;
use OCA\Portaliq\Kernel\Contracts\DestinationMappingInterface;
use OCA\Portaliq\Kernel\Contracts\EmailLinkAddressInterface;
use OCA\Portaliq\Kernel\Contracts\EmailLinkEligibilityInterface;
use OCA\Portaliq\Kernel\Contracts\EmailLinkLimitsInterface;
use OCA\Portaliq\Kernel\Contracts\EmailLinkSenderInterface;
use OCA\Portaliq\Kernel\Contracts\EmailLinkSettingInterface;
use OCA\Portaliq\Kernel\Contracts\EmailLinkTokensInterface;
use OCA\Portaliq\Kernel\Contracts\EventFeedReaderInterface;
use OCA\Portaliq\Kernel\Contracts\EventRsvpServiceInterface;
use OCA\Portaliq\Kernel\Contracts\EventSignupServiceInterface;
use OCA\Portaliq\Kernel\Contracts\ExampleResidentRemoverInterface;
use OCA\Portaliq\Kernel\Contracts\ExampleResidentSignInInterface;
use OCA\Portaliq\Kernel\Contracts\ExampleSiteCatalogueInterface;
use OCA\Portaliq\Kernel\Contracts\ExampleSiteInstallerInterface;
use OCA\Portaliq\Kernel\Contracts\ExampleSiteRemoverInterface;
use OCA\Portaliq\Kernel\Contracts\GlossaryRelationsInterface;
use OCA\Portaliq\Kernel\Contracts\GuardianAudienceFixtureReaderInterface;
use OCA\Portaliq\Kernel\Contracts\HostRootRedirectInterface;
use OCA\Portaliq\Kernel\Contracts\IntakeQueueDrainInterface;
use OCA\Portaliq\Kernel\Contracts\InternalBaseUrlInterface;
use OCA\Portaliq\Kernel\Contracts\LandingSubmissionDrainInterface;
use OCA\Portaliq\Kernel\Contracts\MandatePartiesInterface;
use OCA\Portaliq\Kernel\Contracts\MediaFileInterface;
use OCA\Portaliq\Kernel\Contracts\MediaLibraryReaderInterface;
use OCA\Portaliq\Kernel\Contracts\MediaUsageInterface;
use OCA\Portaliq\Kernel\Contracts\NextcloudAccountProvisionerInterface;
use OCA\Portaliq\Kernel\Contracts\NextcloudRegistrationDoorInterface;
use OCA\Portaliq\Kernel\Contracts\PageHistoryInterface;
use OCA\Portaliq\Kernel\Contracts\PortalAccessRequestServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalAccountActivationServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalAccountLookupInterface;
use OCA\Portaliq\Kernel\Contracts\PortalActionServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalAddressLookupInterface;
use OCA\Portaliq\Kernel\Contracts\PortalApplicantPrefillInterface;
use OCA\Portaliq\Kernel\Contracts\PortalBindingPreviewInterface;
use OCA\Portaliq\Kernel\Contracts\PortalBranchScopeInterface;
use OCA\Portaliq\Kernel\Contracts\PortalCaseTypeChoicesInterface;
use OCA\Portaliq\Kernel\Contracts\PortalCatalogueReaderInterface;
use OCA\Portaliq\Kernel\Contracts\PortalChallengeServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalContactAddressServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalContactServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalDomainVerifierInterface;
use OCA\Portaliq\Kernel\Contracts\PortalDraftDrainInterface;
use OCA\Portaliq\Kernel\Contracts\PortalDraftStoreInterface;
use OCA\Portaliq\Kernel\Contracts\PortalEmbedGuardInterface;
use OCA\Portaliq\Kernel\Contracts\PortalEmbedSnippetDescriptionInterface;
use OCA\Portaliq\Kernel\Contracts\PortalEmbedThrottleInterface;
use OCA\Portaliq\Kernel\Contracts\PortalFormBindingResolverInterface;
use OCA\Portaliq\Kernel\Contracts\PortalHomePageInterface;
use OCA\Portaliq\Kernel\Contracts\PortalIdentityMailerInterface;
use OCA\Portaliq\Kernel\Contracts\PortalIntakeConfirmationInterface;
use OCA\Portaliq\Kernel\Contracts\PortalIntakePaymentsInterface;
use OCA\Portaliq\Kernel\Contracts\PortalIntakeQueueInterface;
use OCA\Portaliq\Kernel\Contracts\PortalInvitationServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalMandateAdminServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalMandateServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalPartyTreeResolverInterface;
use OCA\Portaliq\Kernel\Contracts\PortalReferenceCaseServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalReferenceLinkServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalRegisteredDetailsLinksInterface;
use OCA\Portaliq\Kernel\Contracts\PortalRegisteredDetailsServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalRegistrationPolicyServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalSelfServiceServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalSubmissionChecksInterface;
use OCA\Portaliq\Kernel\Contracts\PublicAssistantChannelInterface;
use OCA\Portaliq\Kernel\Contracts\PublicSourceScopeInterface;
use OCA\Portaliq\Kernel\Contracts\SharedBlockPortalsInterface;
use OCA\Portaliq\Kernel\Contracts\SignInAddressChangeInterface;
use OCA\Portaliq\Kernel\Contracts\WaitingAccountInvitationInterface;
use OCA\Portaliq\Service\Actions\PortalActionService;
use OCA\Portaliq\Service\Assistant\PublicAssistantChannel;
use OCA\Portaliq\Service\Assistant\PublicSourceScope;
use OCA\Portaliq\Service\Availability\AvailabilityProbe;
use OCA\Portaliq\Service\Availability\AvailabilityReport;
use OCA\Portaliq\Service\Availability\AvailabilityRollup;
use OCA\Portaliq\Service\Availability\AvailabilityStore;
use OCA\Portaliq\Service\Branch\BranchChoice;
use OCA\Portaliq\Service\Branch\PortalBranchScope;
use OCA\Portaliq\Service\CaseTypeColumns\CaseTypeColumnSettings;
use OCA\Portaliq\Service\CaseTypeColumns\ColumnOverrides;
use OCA\Portaliq\Service\Cms\AccessibilityFraming;
use OCA\Portaliq\Service\Cms\AccessibilityMeasurements;
use OCA\Portaliq\Service\Cms\AccessibilityRun;
use OCA\Portaliq\Service\Cms\AccessibilityStatement;
use OCA\Portaliq\Service\Cms\CmsPublishValidator;
use OCA\Portaliq\Service\Cms\GlossaryRelations;
use OCA\Portaliq\Service\Cms\MediaFile;
use OCA\Portaliq\Service\Cms\MediaLibraryReader;
use OCA\Portaliq\Service\Cms\MediaUsage;
use OCA\Portaliq\Service\Cms\PageHistory;
use OCA\Portaliq\Service\Cms\PortalDomainVerifier;
use OCA\Portaliq\Service\Cms\PortalHomePage;
use OCA\Portaliq\Service\Cms\SharedBlockPortals;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentRemover;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentSignIn;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteCatalogue;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteInstaller;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteRemover;
use OCA\Portaliq\Service\Identity\ContactAddressValues;
use OCA\Portaliq\Service\Identity\ContactConfirmationMailer;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkAddress;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkEligibility;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkLimits;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkSender;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkSetting;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkTokens;
use OCA\Portaliq\Service\Identity\EmailLink\SignInAddressChange;
use OCA\Portaliq\Service\Identity\MandateParties;
use OCA\Portaliq\Service\Identity\NextcloudAccountProvisioner;
use OCA\Portaliq\Service\Identity\NextcloudRegistrationDoor;
use OCA\Portaliq\Service\Identity\PortalAccessRequestService;
use OCA\Portaliq\Service\Identity\PortalAccountActivationService;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Identity\PortalChallengeService;
use OCA\Portaliq\Service\Identity\PortalContactAddressService;
use OCA\Portaliq\Service\Identity\PortalContactService;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\Identity\PortalInvitationService;
use OCA\Portaliq\Service\Identity\PortalMandateAdminService;
use OCA\Portaliq\Service\Identity\PortalMandateService;
use OCA\Portaliq\Service\Identity\PortalPartyTreeResolver;
use OCA\Portaliq\Service\Identity\PortalReferenceCaseService;
use OCA\Portaliq\Service\Identity\PortalReferenceLinkService;
use OCA\Portaliq\Service\Identity\PortalRegisteredDetailsLinks;
use OCA\Portaliq\Service\Identity\PortalRegisteredDetailsService;
use OCA\Portaliq\Service\Identity\PortalRegistrationPolicyService;
use OCA\Portaliq\Service\Identity\PortalSelfServiceService;
use OCA\Portaliq\Service\Identity\WaitingAccountInvitation;
use OCA\Portaliq\Service\Intake\ActionDraft;
use OCA\Portaliq\Service\Intake\DestinationDraft;
use OCA\Portaliq\Service\Intake\DestinationIntake;
use OCA\Portaliq\Service\Intake\DestinationMapping;
use OCA\Portaliq\Service\Intake\IntakeQueueDrain;
use OCA\Portaliq\Service\Intake\LandingSubmissionDrain;
use OCA\Portaliq\Service\Intake\PortalAddressLookup;
use OCA\Portaliq\Service\Intake\PortalApplicantPrefill;
use OCA\Portaliq\Service\Intake\PortalBindingPreview;
use OCA\Portaliq\Service\Intake\PortalCaseTypeChoices;
use OCA\Portaliq\Service\Intake\PortalCatalogueReader;
use OCA\Portaliq\Service\Intake\PortalDraftDrain;
use OCA\Portaliq\Service\Intake\PortalDraftStore;
use OCA\Portaliq\Service\Intake\PortalEmbedGuard;
use OCA\Portaliq\Service\Intake\PortalEmbedSnippetDescription;
use OCA\Portaliq\Service\Intake\PortalEmbedThrottle;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalIntakeConfirmation;
use OCA\Portaliq\Service\Intake\PortalIntakePayments;
use OCA\Portaliq\Service\Intake\PortalIntakeQueue;
use OCA\Portaliq\Service\Intake\PortalSubmissionChecks;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Portaliq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec exclude Structural contract wiring, no functional requirement.
 */
final class ContractRegistrar {

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec exclude Structural contract wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(AccessibilityFramingInterface::class, AccessibilityFraming::class);
		$context->registerServiceAlias(AccessibilityMeasurementsInterface::class, AccessibilityMeasurements::class);
		$context->registerServiceAlias(AccessibilityRunInterface::class, AccessibilityRun::class);
		$context->registerServiceAlias(AccessibilityStatementInterface::class, AccessibilityStatement::class);
		$context->registerServiceAlias(ActionAuthServiceInterface::class, ActionAuthService::class);
		$context->registerServiceAlias(ActionDraftInterface::class, ActionDraft::class);
		$context->registerServiceAlias(ActionReminderServiceInterface::class, ActionReminderService::class);
		$context->registerServiceAlias(ActivityAttendanceServiceInterface::class, ActivityAttendanceService::class);
		$context->registerServiceAlias(ActivityContributionServiceInterface::class, ActivityContributionService::class);
		$context->registerServiceAlias(ActivityDraftInterface::class, ActivityDraft::class);
		$context->registerServiceAlias(ActivityFeedReaderInterface::class, ActivityFeedReader::class);
		$context->registerServiceAlias(ActivityPlacesInterface::class, ActivityPlaces::class);
		$context->registerServiceAlias(ActivityRosterInterface::class, ActivityRoster::class);
		$context->registerServiceAlias(ActivitySignupServiceInterface::class, ActivitySignupService::class);
		$context->registerServiceAlias(ActivityStoreInterface::class, ActivityStore::class);
		$context->registerServiceAlias(AdminMenuAccessInterface::class, AdminMenuAccess::class);
		$context->registerServiceAlias(AuditTrailServiceInterface::class, AuditTrailService::class);
		$context->registerServiceAlias(AvailabilityProbeInterface::class, AvailabilityProbe::class);
		$context->registerServiceAlias(AvailabilityReportInterface::class, AvailabilityReport::class);
		$context->registerServiceAlias(AvailabilityRollupInterface::class, AvailabilityRollup::class);
		$context->registerServiceAlias(AvailabilityStoreInterface::class, AvailabilityStore::class);
		$context->registerServiceAlias(BranchChoiceInterface::class, BranchChoice::class);
		$context->registerServiceAlias(CaseRowMarkerInterface::class, CaseRowMarker::class);
		$context->registerServiceAlias(CaseTypeColumnSettingsInterface::class, CaseTypeColumnSettings::class);
		$context->registerServiceAlias(CaseTypeNamesInterface::class, CaseTypeNames::class);
		$context->registerServiceAlias(CaseTypeReaderInterface::class, CaseTypeReader::class);
		$context->registerServiceAlias(CaseTypeVisibilityInterface::class, CaseTypeVisibility::class);
		$context->registerServiceAlias(CitizenCaseDocumentsInterface::class, CitizenCaseDocuments::class);
		$context->registerServiceAlias(CitizenCaseProjectionInterface::class, CitizenCaseProjection::class);
		$context->registerServiceAlias(CitizenWritableSetResolverInterface::class, CitizenWritableSetResolver::class);
		$context->registerServiceAlias(CitizenWriteRecorderInterface::class, CitizenWriteRecorder::class);
		$context->registerServiceAlias(CitizenWriteThrottleInterface::class, CitizenWriteThrottle::class);
		$context->registerServiceAlias(CmsPublishValidatorInterface::class, CmsPublishValidator::class);
		$context->registerServiceAlias(CmsReaderInterface::class, CmsReader::class);
		$context->registerServiceAlias(CollectionDocumentsInterface::class, CollectionDocuments::class);
		$context->registerServiceAlias(ColumnOverridesInterface::class, ColumnOverrides::class);
		$context->registerServiceAlias(ContactAddressValuesInterface::class, ContactAddressValues::class);
		$context->registerServiceAlias(ContactConfirmationMailerInterface::class, ContactConfirmationMailer::class);
		$context->registerServiceAlias(DemoDataServiceInterface::class, DemoDataService::class);
		$context->registerServiceAlias(DestinationDraftInterface::class, DestinationDraft::class);
		$context->registerServiceAlias(DestinationIntakeInterface::class, DestinationIntake::class);
		$context->registerServiceAlias(DestinationMappingInterface::class, DestinationMapping::class);
		$context->registerServiceAlias(EmailLinkAddressInterface::class, EmailLinkAddress::class);
		$context->registerServiceAlias(EmailLinkEligibilityInterface::class, EmailLinkEligibility::class);
		$context->registerServiceAlias(EmailLinkLimitsInterface::class, EmailLinkLimits::class);
		$context->registerServiceAlias(EmailLinkSenderInterface::class, EmailLinkSender::class);
		$context->registerServiceAlias(EmailLinkSettingInterface::class, EmailLinkSetting::class);
		$context->registerServiceAlias(EmailLinkTokensInterface::class, EmailLinkTokens::class);
		$context->registerServiceAlias(EventFeedReaderInterface::class, EventFeedReader::class);
		$context->registerServiceAlias(EventRsvpServiceInterface::class, EventRsvpService::class);
		$context->registerServiceAlias(EventSignupServiceInterface::class, EventSignupService::class);
		$context->registerServiceAlias(ExampleResidentRemoverInterface::class, ExampleResidentRemover::class);
		$context->registerServiceAlias(ExampleResidentSignInInterface::class, ExampleResidentSignIn::class);
		$context->registerServiceAlias(ExampleSiteCatalogueInterface::class, ExampleSiteCatalogue::class);
		$context->registerServiceAlias(ExampleSiteInstallerInterface::class, ExampleSiteInstaller::class);
		$context->registerServiceAlias(ExampleSiteRemoverInterface::class, ExampleSiteRemover::class);
		$context->registerServiceAlias(GlossaryRelationsInterface::class, GlossaryRelations::class);
		$context->registerServiceAlias(GuardianAudienceFixtureReaderInterface::class, GuardianAudienceFixtureReader::class);
		$context->registerServiceAlias(HostRootRedirectInterface::class, HostRootRedirect::class);
		$context->registerServiceAlias(IntakeQueueDrainInterface::class, IntakeQueueDrain::class);
		$context->registerServiceAlias(InternalBaseUrlInterface::class, InternalBaseUrl::class);
		$context->registerServiceAlias(LandingSubmissionDrainInterface::class, LandingSubmissionDrain::class);
		$context->registerServiceAlias(MandatePartiesInterface::class, MandateParties::class);
		$context->registerServiceAlias(MediaFileInterface::class, MediaFile::class);
		$context->registerServiceAlias(MediaLibraryReaderInterface::class, MediaLibraryReader::class);
		$context->registerServiceAlias(MediaUsageInterface::class, MediaUsage::class);
		$context->registerServiceAlias(NextcloudAccountProvisionerInterface::class, NextcloudAccountProvisioner::class);
		$context->registerServiceAlias(NextcloudRegistrationDoorInterface::class, NextcloudRegistrationDoor::class);
		$context->registerServiceAlias(PageHistoryInterface::class, PageHistory::class);
		$context->registerServiceAlias(PortalAccessRequestServiceInterface::class, PortalAccessRequestService::class);
		$context->registerServiceAlias(PortalAccountActivationServiceInterface::class, PortalAccountActivationService::class);
		$context->registerServiceAlias(PortalAccountLookupInterface::class, PortalAccountLookup::class);
		$context->registerServiceAlias(PortalActionServiceInterface::class, PortalActionService::class);
		$context->registerServiceAlias(PortalAddressLookupInterface::class, PortalAddressLookup::class);
		$context->registerServiceAlias(PortalApplicantPrefillInterface::class, PortalApplicantPrefill::class);
		$context->registerServiceAlias(PortalBindingPreviewInterface::class, PortalBindingPreview::class);
		$context->registerServiceAlias(PortalBranchScopeInterface::class, PortalBranchScope::class);
		$context->registerServiceAlias(PortalCaseTypeChoicesInterface::class, PortalCaseTypeChoices::class);
		$context->registerServiceAlias(PortalCatalogueReaderInterface::class, PortalCatalogueReader::class);
		$context->registerServiceAlias(PortalChallengeServiceInterface::class, PortalChallengeService::class);
		$context->registerServiceAlias(PortalContactAddressServiceInterface::class, PortalContactAddressService::class);
		$context->registerServiceAlias(PortalContactServiceInterface::class, PortalContactService::class);
		$context->registerServiceAlias(PortalDomainVerifierInterface::class, PortalDomainVerifier::class);
		$context->registerServiceAlias(PortalDraftDrainInterface::class, PortalDraftDrain::class);
		$context->registerServiceAlias(PortalDraftStoreInterface::class, PortalDraftStore::class);
		$context->registerServiceAlias(PortalEmbedGuardInterface::class, PortalEmbedGuard::class);
		$context->registerServiceAlias(PortalEmbedSnippetDescriptionInterface::class, PortalEmbedSnippetDescription::class);
		$context->registerServiceAlias(PortalEmbedThrottleInterface::class, PortalEmbedThrottle::class);
		$context->registerServiceAlias(PortalFormBindingResolverInterface::class, PortalFormBindingResolver::class);
		$context->registerServiceAlias(PortalHomePageInterface::class, PortalHomePage::class);
		$context->registerServiceAlias(PortalIdentityMailerInterface::class, PortalIdentityMailer::class);
		$context->registerServiceAlias(PortalIntakeConfirmationInterface::class, PortalIntakeConfirmation::class);
		$context->registerServiceAlias(PortalIntakePaymentsInterface::class, PortalIntakePayments::class);
		$context->registerServiceAlias(PortalIntakeQueueInterface::class, PortalIntakeQueue::class);
		$context->registerServiceAlias(PortalInvitationServiceInterface::class, PortalInvitationService::class);
		$context->registerServiceAlias(PortalMandateAdminServiceInterface::class, PortalMandateAdminService::class);
		$context->registerServiceAlias(PortalMandateServiceInterface::class, PortalMandateService::class);
		$context->registerServiceAlias(PortalPartyTreeResolverInterface::class, PortalPartyTreeResolver::class);
		$context->registerServiceAlias(PortalReferenceCaseServiceInterface::class, PortalReferenceCaseService::class);
		$context->registerServiceAlias(PortalReferenceLinkServiceInterface::class, PortalReferenceLinkService::class);
		$context->registerServiceAlias(PortalRegisteredDetailsLinksInterface::class, PortalRegisteredDetailsLinks::class);
		$context->registerServiceAlias(PortalRegisteredDetailsServiceInterface::class, PortalRegisteredDetailsService::class);
		$context->registerServiceAlias(PortalRegistrationPolicyServiceInterface::class, PortalRegistrationPolicyService::class);
		$context->registerServiceAlias(PortalSelfServiceServiceInterface::class, PortalSelfServiceService::class);
		$context->registerServiceAlias(PortalSubmissionChecksInterface::class, PortalSubmissionChecks::class);
		$context->registerServiceAlias(PublicAssistantChannelInterface::class, PublicAssistantChannel::class);
		$context->registerServiceAlias(PublicSourceScopeInterface::class, PublicSourceScope::class);
		$context->registerServiceAlias(SharedBlockPortalsInterface::class, SharedBlockPortals::class);
		$context->registerServiceAlias(SignInAddressChangeInterface::class, SignInAddressChange::class);
		$context->registerServiceAlias(WaitingAccountInvitationInterface::class, WaitingAccountInvitation::class);
	}//end register()
}//end class
