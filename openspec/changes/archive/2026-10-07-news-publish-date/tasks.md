# Tasks: news-publish-date

- [x] **T1**: `newsItem.publishedAt` (date-time); `NewsController::publish` stamps it from `ITimeFactory`, `unpublish` clears it
  - PHPUnit `NewsControllerTest::testPublishStampsTheMomentAndTakeBackClearsIt`
- [x] **T2**: `NewsFeedReader` and `newestNewsFirst()` sort on `publishedAt` first
  - PHPUnit `NewsFeedReaderTest::testFeedSortsOnThePublishMomentFirst`; node `tests/record-page.spec.mjs` "a draft written long ago and published today is the newest news"
- [x] **T3**: repair step `BackfillNewsPublishedAt` stamps published items without the moment with their creation moment
  - PHPUnit `BackfillNewsPublishedAtTest` (three cases: stamps only what needs it and is idempotent, reads every page, does nothing without the schema)
- [x] **T4**: the staff News list shows "Published on"; `NewsItem` shows "Published on {date}" (nl "Gepubliceerd op {date}")
  - node `tests/site-inbox-pages.spec.mjs` "a news item says when it was published, and nothing while it has no date"
