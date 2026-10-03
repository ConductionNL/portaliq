# Proposal: inbox-berichtenbox-channel

## Why

A municipality sends a decision to a resident through the portal. Many residents never open that portal, but do read the government message box on MijnOverheid. Tenders ask for the decision to arrive in both places.

Portaliq matrix, row `dem-tnd-berichtenbox`, "Deliver a decision or message to the resident's MijnOverheid Berichtenbox as well as to the portal." Origin `tender`, originUrl https://www.tenderned.nl/aankondigingen/overzicht/407031, note:

> TenderNed 407031 Gemeente Gulpen-Wittem: 'beschikking kenbaar ... via het inwonerportaal, via de mail, via de MijnOverheid Berichtenbox'; 415380 Sudwest-Fryslan: 'koppeling met de Berichtenbox Overheid'

The matrix `built.evidence`: "grep -riE 'berichtenbox|mijnoverheid' lib src: 0 hits; lib/Settings/portaliq_register.json portalNotification.channel: 'Only email is implemented'". The `built.note`: "No outbound channel to the MijnOverheid Berichtenbox exists; portal messages stay in the portaliq inbox and the only out-of-band channel is e-mail."

The competitor cells rated `yes`, quoted from `gap-rows.json`:

- xxllnc Zaken PIP: "backend/perl-api/lib/Zaaksysteem/Backend/Sysin/Modules/MijnOverheid.pm:535 send_berichtenbox_message; frontend-mono/packages/communication-module/src/components/MessageForm/Mijnoverheid/MijnoverheidDialog.tsx [reached on staff case communication; needs the MijnOverheid interface]". No URL recorded.
- MijnOverheid: "https://www.logius.nl/onze-dienstverlening/interactie/voorzieningen/mijnoverheid/mijnoverheid-berichtenbox organisations can 'digitaal post sturen naar de miljoenen gebruikers van MijnOverheid', with attachments; MijnOverheid is the Berichtenbox itself". URL: https://www.logius.nl/onze-dienstverlening/interactie/voorzieningen/mijnoverheid/mijnoverheid-berichtenbox

Integriq holds the transport. Its open change `berichtenbox-digital-post-adapter` (capability `digital-post-adapter`) ships `DigitalPostSendRequestedEvent`, a typed ADR-041 command with a result slot, and `DigitalPostDeliveredEvent` on every status change. What is missing is portaliq deciding which inbox message also goes to the government message box, and telling the resident what happened to it.

## What changes

- **A government message box is a portal channel.** Next to e-mail and push, portaliq gains a `messageBox` channel. Portaliq's code and screens name it generically; the organisation sets the label residents read. The product name stays inside integriq's adapter.
- **The case app decides which message goes, and to whom.** An inbox collection names a method on the case app's portal provider that returns the recipient identity for one message, or nothing. Portaliq never stores that identity.
- **Portaliq asks integriq to send it.** For a message the case app says should go, portaliq dispatches `DigitalPostSendRequestedEvent` with the organisation's configured source, the message's subject, body and attachments, and records the attempt.
- **The resident sees where it went.** The inbox message shows "Also sent to {label}" once integriq reports it delivered, and nothing when the send was simulated or refused.
- **The resident can turn it off.** The notification settings gain one choice for this channel, when the organisation offers it.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `dem-tnd-berichtenbox` | Deliver a decision or message to the resident's MijnOverheid Berichtenbox as well as to the portal. | no | A portal channel to the government message box, over integriq's digital post command, with status shown to the resident. |

## Existing work it builds on

- Integriq `openspec/changes/berichtenbox-digital-post-adapter` (open) and its spec `digital-post-adapter`: REQ-DPA-002 (the typed send with a result slot and `DigitalPostDeliveredEvent`), REQ-DPA-005 (the feature flag, and no simulated success on a flagged instance).
- `openspec/changes/archive/2026-07-24-portal-notifications-dispatch`: `NotificationDispatchJob` and the `portalNotification` log this channel writes to.
- `inbox-notifications-and-preferences` (this pass): the per-kind, per-channel preferences and the settings section this channel joins.
- `openspec/changes/archive/2026-07-23-portal-inbox-v2`: the unified inbox that shows the delivery line.

## Out of scope

- Receiving post from the message box. Integriq sends inbound items to filinq's intake (REQ-DPA-003).
- Postex and paper post. Integriq's seam has the binding; a portal channel for it can follow the same pattern.
- The live network leg, credentials and certificates. Integriq's own change names those blockers.
- Letters a case app already sends to the message box itself. See design.md, risks.

## Sibling halves

- **ConductionNL/integriq owes** the live binding of its digital post adapter, which its tasks list as blocked on Logius OAuth credentials, a PKIoverheid certificate and OpenRegister's `CredentialBrokerService::issueSigningMaterial`. Until then every send is simulated or refused, and portaliq shows no delivery.
- **ConductionNL/dossiq owes** the `messageBox` declaration on its `berichten` inbox collection and the recipient method on its portal provider, returning the applicant's identity for a message it wants delivered there. Dossiq holds that identity; portaliq does not.
