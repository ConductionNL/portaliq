## ADDED Requirements

### Requirement: An article holds its body as markdown and its own identity

portaliq SHALL keep marketing articles in an `article` schema in the `portaliq` register. An article SHALL carry a title, a URL-safe slug, a summary, a markdown body, a hero image, links, tags, a language, an author, a status and, when it came from another app, a `legacyRef`. The slug SHALL be unique within the register. The body SHALL be markdown and never HTML, so a mailing, a post and a page can each render it their own way. An article SHALL belong to the organisation and to no single portal.

#### Scenario: An article is created as a draft

- **WHEN** an editor creates an article with a title and a body
- **THEN** the article SHALL be stored with status `draft`, the author set to the editor and `publishedAt` empty
- **AND** a slug SHALL be derived from the title when none was given

#### Scenario: A second article cannot claim a slug already in use

- **GIVEN** an article whose slug is `nieuwe-release-najaar`
- **WHEN** an editor creates another article with the same slug
- **THEN** the request SHALL be refused with a validation error naming the slug

### Requirement: An article moves through a declared lifecycle

The status SHALL be `draft`, `review`, `published` or `archived`, with the moves declared on the schema. Publishing SHALL stamp `publishedAt` once; a second publish SHALL NOT move it. Archiving SHALL keep the article readable and every reference to it intact.

#### Scenario: Publishing twice keeps the first publication moment

- **GIVEN** a published article with `publishedAt` set
- **WHEN** it is published a second time
- **THEN** `publishedAt` SHALL still hold the first value

#### Scenario: A move the lifecycle does not declare is refused

- **GIVEN** an archived article
- **WHEN** a caller tries to publish it directly
- **THEN** the move SHALL be refused and the status SHALL still be `archived`

### Requirement: An agent-drafted article is marked as such

An article written or changed by an agent SHALL carry `agentAuthored` true and `agentAuthoredBy` naming the agent, set by the write path and never taken from a request (ADR-088). The mark SHALL be shown wherever the article is read.

#### Scenario: A client cannot claim an article was written by a person

@e2e exclude a request-body tampering check; covered by ArticleServiceTest.

- **WHEN** a create or update request carries `agentAuthored` or `agentAuthoredBy`
- **THEN** both SHALL be ignored and the stored values SHALL be the ones the write path set

### Requirement: An article reports where it has been used

An article SHALL answer which objects use it. portaliq SHALL ask by dispatching `ArticleUsagesRequestedEvent`, and every app that references articles SHALL add its own usages, each with an id, a display name, a kind, the app and a link. portaliq SHALL add its own pages that name the article. The answer SHALL be built at read time and SHALL NOT be stored. An unused article SHALL report an empty list.

#### Scenario: A pipelinq template shows up as a usage

- **GIVEN** an article named by a pipelinq campaign template
- **WHEN** an editor opens the article
- **THEN** the usage list SHALL name the template, marked as pipelinq, with a link to it

#### Scenario: An unused article reports an empty list

- **GIVEN** an article nothing references
- **WHEN** its usages are read
- **THEN** the answer SHALL be an empty list and the response SHALL be successful

### Requirement: A marketer writes and reads an article in portaliq

The portaliq menu SHALL carry Articles under Website, after News. The index SHALL open as a table with a cards view (hero image, title, summary, status). The article page SHALL render the body as formatted text, show the hero image and the agent mark, offer the lifecycle actions and list where the article is used. The editor SHALL write the body with a markdown editor and pick the hero image from Nextcloud Files. A hero image written `app:<app>/<file>` SHALL resolve to that app's `img/` folder, so articles moved from pipelinq keep their images.

#### Scenario: The Articles page lists the articles

- **GIVEN** an instance with the demo articles
- **WHEN** an editor opens Website and then Articles
- **THEN** the page SHALL list the demo articles, each with its title and status

#### Scenario: A marketer writes an article and publishes it

- **WHEN** an editor creates an article, writes a body and publishes it
- **THEN** the article SHALL appear on the Articles page with status `published`

### Requirement: A page can show an article

A `page` with a markdown body SHALL be able to name an article. The site SHALL render that article's body and hero image in place, so a campaign page carries no copy of the text. A page that names an archived article SHALL keep showing it.

#### Scenario: A campaign page shows the article it names

- **GIVEN** a published article and a page whose body names it
- **WHEN** a visitor opens the page
- **THEN** the page SHALL show the article's title, hero image and formatted body
