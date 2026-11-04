# Screens

The contract between portaliq and the apps that contribute to it renders on these portal screens.

- portaliq/MijnZaken mijn zaken: the resident's case list, filled by contributing apps, including cases shared with a partner (cas-contrib-list, con-contributed-pages-render, dem-tnd-joint-case-partner) https://identity.conduction.nl/screens/board?id=portaliq/MijnZaken
- portaliq/Zaak uw zaak: the case page with status transitions, file uploads and contact moments (cas-detail, act-row-transition, act-file-upload-generic, dem-fr-case-contact-moments) https://identity.conduction.nl/screens/board?id=portaliq/Zaak
- portaliq/MobielZaak Telefoon: uw zaak: the case page on a phone (cas-detail) https://identity.conduction.nl/screens/board?id=portaliq/MobielZaak
- portaliq/ZaakWooVerzoek uw Woo-verzoek: the case page for a Woo request, with its contact moments (cas-detail, dem-fr-case-contact-moments) https://identity.conduction.nl/screens/board?id=portaliq/ZaakWooVerzoek
- portaliq/MeldingIndienen melding indienen: the form that creates an object in a contributing app (act-create-object) https://identity.conduction.nl/screens/board?id=portaliq/MeldingIndienen
- portaliq/MijnKind mijn kind: a guardian's view of their child, scoped by list membership (sib-learniq-gua-guardian-sees-the-child) https://identity.conduction.nl/screens/board?id=portaliq/MijnKind
- portaliq/MijnOverzicht overzicht: the overview page with blocks contributed by other apps (con-contributed-pages-render, con-contributed-blocks) https://identity.conduction.nl/screens/board?id=portaliq/MijnOverzicht
- portaliq/ThemaOverzicht parkeren: a theme page built from contributed pages and blocks (con-contributed-pages-render, con-contributed-blocks) https://identity.conduction.nl/screens/board?id=portaliq/ThemaOverzicht
- portaliq/PtZaaktypeWeergave weergave van een zaaktype: the admin settings for how a contributed case type shows in the portal (con-ui-config-v3) https://identity.conduction.nl/screens/board?id=portaliq/PtZaaktypeWeergave
- portaliq/PtRegioportaal Site: samenwerken in de regio: the regional portal page where stackiq shares its catalogue (sib-stackiq-share-portal) https://identity.conduction.nl/screens/board?id=portaliq/PtRegioportaal
- wilgenboom/Detail mijn kind: the record page of one child, headed "Vera Hulstkamp", with her blocks https://identity.conduction.nl/screens/board?id=wilgenboom/Detail
- wilgenboom/MijnOverzicht overzicht: the news block as rows: who, date and title, no body https://identity.conduction.nl/screens/board?id=wilgenboom/MijnOverzicht
- wilgenboom/MijnLijst afwezigheid: status labels such as "Goedgekeurd" and figure units "dag" and "dagen" https://identity.conduction.nl/screens/board?id=wilgenboom/MijnLijst
- wilgenboom/MijnMenu Onderdeel: menu Mijn Wilgenboom: contributed pages in the menu and per child, such as "Afwezigheid" (REQ-SMO-020) https://identity.conduction.nl/screens/board?id=wilgenboom/MijnMenu
- esdoornveen/Detail mijn BPV: collection rows as cards with a progress figure in hours (REQ-SMO-028) https://identity.conduction.nl/screens/board?id=esdoornveen/Detail
- vaartveld/MijnOverzicht overzicht: a collection block under its own heading "Laatste cijfers" https://identity.conduction.nl/screens/board?id=vaartveld/MijnOverzicht
- portaliq/GedeeldDossier the own dossier: items read from an app, removing one, "Niet meer openbaar", the share link and a record text (REQ-MYD-002, REQ-MYD-003) https://identity.conduction.nl/screens/board?id=portaliq/GedeeldDossier
- huisstijl/MijnMenu the menu of Mijn Zuiddrecht: the group "Vragen en contact" holding contributed pages https://identity.conduction.nl/screens/board?id=huisstijl/MijnMenu
- portaliq/Inloggen inloggen: the sign-in page https://identity.conduction.nl/screens/board?id=portaliq/Inloggen
- esdoornveen/MijnOverzicht overzicht: the card "Vandaag" and the hours bar in three parts https://identity.conduction.nl/screens/board?id=esdoornveen/MijnOverzicht
- esdoornveen/MijnLijst BPV-uren: rows with a day, a status and an action https://identity.conduction.nl/screens/board?id=esdoornveen/MijnLijst
- esdoornveen/Documenten documenten: the documents block https://identity.conduction.nl/screens/board?id=esdoornveen/Documenten
- warmtepompacademie/MijnOverzicht overzicht: the card "Eerst dit" https://identity.conduction.nl/screens/board?id=warmtepompacademie/MijnOverzicht
- warmtepompacademie/MijnLijst inschrijvingen: rows with a date tile, a status and a note https://identity.conduction.nl/screens/board?id=warmtepompacademie/MijnLijst
- portaliq/Dossiers dossiers: "Stel een vraag" and "Start een Woo-verzoek" as contributed actions (REQ-WJE-004) https://identity.conduction.nl/screens/board?id=portaliq/Dossiers

Components: CnActionsMenu, CnAppNav, CnBreadcrumbs, CnBuildiqEditButton, CnDataTable, CnJsonViewer, CnRowActions, CnSettingsPage, CnSettingsSection, CnStatusBadge
