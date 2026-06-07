# Changelog

## [5.12.1](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.12.0...v5.12.1) - 2026-06-07


### Fixed

* **campaigns:** plugin credit to campaign edit templates ([029909c](https://github.com/LindemannRock/craft-campaign-manager/commit/029909ce9e1170b7d7fd5223f96569e72b86e17e))

## [5.12.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.11.0...v5.12.0) - 2026-06-07


### Added

* add plugin credit component to campaign edit template ([9511160](https://github.com/LindemannRock/craft-campaign-manager/commit/9511160ced559d7a7af5bb894e34ce3c368646ea))
* add sortable columns to activity logs table ([32551b9](https://github.com/LindemannRock/craft-campaign-manager/commit/32551b9075f6c5a540d81832076e12aef58781b3))
* add static analysis script for CI workflow ([d517c11](https://github.com/LindemannRock/craft-campaign-manager/commit/d517c11ae4763f3225fedc55a81ebac9e0296553))
* **campaign:** add date rendering for created and updated attributes ([043108e](https://github.com/LindemannRock/craft-campaign-manager/commit/043108e924b7ad74a613d4d1c6610bd146c2bbbe))
* **campaign:** add precomputed recipient counts for campaigns ([6584eb6](https://github.com/LindemannRock/craft-campaign-manager/commit/6584eb6145182d907ecbcf6c05dd3869f81472c9))
* **campaigns:** update date labels for created and updated attributes ([b0601f4](https://github.com/LindemannRock/craft-campaign-manager/commit/b0601f494c233fb04eecbcb52656769770747b3c))
* **controllers:** add parameter validation and user permissions for recipient actions ([5ea0f68](https://github.com/LindemannRock/craft-campaign-manager/commit/5ea0f683f1394858208dbd5687ba87116a844c6f))
* **controllers:** enhance activity log sorting and pagination functionality ([61f2186](https://github.com/LindemannRock/craft-campaign-manager/commit/61f21869357f2984e6a72eb09d622b86fda046d9))
* expand default date range options for analytics ([62ce865](https://github.com/LindemannRock/craft-campaign-manager/commit/62ce8658d2dbf511c76435496537cf70fa8c5686))
* **i18n:** add common campaign-related translations and validation messages ([45db777](https://github.com/LindemannRock/craft-campaign-manager/commit/45db777e1bb57cc280abf351fb5a71cc6aff471d))
* **i18n:** add new translation keys for user notifications ([b796128](https://github.com/LindemannRock/craft-campaign-manager/commit/b7961281b843a5044896b1643361a3157ef82e3e))
* **i18n:** add new translation keys for various attributes across locales ([9c15c04](https://github.com/LindemannRock/craft-campaign-manager/commit/9c15c0473cb5e671345012313d251e61ae5ce741))
* **i18n:** replace 'app' translations with 'campaign-manager' for consistency ([5da60f1](https://github.com/LindemannRock/craft-campaign-manager/commit/5da60f10850b26af710872a15ad481aef0d5208f))
* **i18n:** replace 'app' translations with 'campaign-manager' for status labels ([189001e](https://github.com/LindemannRock/craft-campaign-manager/commit/189001e45d6874060cd45974ceb10f9910a54225))
* **settings:** add labels for invitation and logging settings ([c2655ee](https://github.com/LindemannRock/craft-campaign-manager/commit/c2655eeba0e4686d1ede1c42374d8d138e9fc601))
* **sms:** add siteId parameter for SMS analytics attribution ([c514505](https://github.com/LindemannRock/craft-campaign-manager/commit/c514505fbe5600ff8c1edb7ec6fa9b63a7154406))
* **tests:** add ElementIndexDateFormattingTest for date formatting ([eafb20f](https://github.com/LindemannRock/craft-campaign-manager/commit/eafb20f2d677ad9062cff85f16e54e46400a53f7))


### Fixed

* correct campaign deletion notice message ([89510dd](https://github.com/LindemannRock/craft-campaign-manager/commit/89510ddb09d3a084f248f53b381538666afbae6b))
* correct error message for invalid phone number validation ([3b05ccb](https://github.com/LindemannRock/craft-campaign-manager/commit/3b05ccb5102effa0c23bfd9ac2fb057af28e49ee))
* **i18n:** correct campaign deletion message in multiple locales ([7d939b8](https://github.com/LindemannRock/craft-campaign-manager/commit/7d939b80ac64638d8857257931dcabd2c9695631))
* **i18n:** correct german translations for analytics and logs ([42d46b2](https://github.com/LindemannRock/craft-campaign-manager/commit/42d46b2ac404980df725e9152f7c569c837e0580))
* **i18n:** correct permission error messages in controllers ([5e37f18](https://github.com/LindemannRock/craft-campaign-manager/commit/5e37f18516b907fa1506430ea72ced781f6e35e3))
* **i18n:** correct Portuguese translations for logs and activity logs ([9cb35eb](https://github.com/LindemannRock/craft-campaign-manager/commit/9cb35ebdf1acc83a4ae79c9fe12568d2006a81a2))
* **i18n:** correct punctuation in Japanese translation strings ([a65d75f](https://github.com/LindemannRock/craft-campaign-manager/commit/a65d75ff3bdf8c721f9500435abcfac9357a03c9))
* **i18n:** correct punctuation in Japanese translation strings ([2635566](https://github.com/LindemannRock/craft-campaign-manager/commit/2635566b31b8e5b6c0bb70ea2fb7effdc8085d4e))
* **i18n:** correct translations for campaign manager strings ([257c2da](https://github.com/LindemannRock/craft-campaign-manager/commit/257c2da30c347782a34d2fe2736075d7135333a7))

## [5.11.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.10.3...v5.11.0) - 2026-05-22


### Added

* **activity-logs:** optimize activity log retrieval with batch fetching ([9a45ec1](https://github.com/LindemannRock/craft-campaign-manager/commit/9a45ec1411a463dbeca36e8c626d4a0a68de88f2))
* **analytics:** optimize recipient statistics aggregation with single query ([940d7ee](https://github.com/LindemannRock/craft-campaign-manager/commit/940d7ee6fa0742d37f1638799a6b29194256c0f2))
* **analytics:** optimize recipient statistics retrieval with aggregate query ([f54f526](https://github.com/LindemannRock/craft-campaign-manager/commit/f54f5263fd3e2797710cfbf98ab5ac2468154c2d))
* **analytics:** require login for export action and optimize data retrieval ([ecfa125](https://github.com/LindemannRock/craft-campaign-manager/commit/ecfa125f81c31e063fb10a7e70506f4e43cea3e9))
* **campaigns:** enhance campaign job notifications with queue links ([1338e50](https://github.com/LindemannRock/craft-campaign-manager/commit/1338e50012a8d0535ae534a4e630d289ee0d7d90))
* **campaigns:** require login for save and delete actions ([513b384](https://github.com/LindemannRock/craft-campaign-manager/commit/513b384dc63dee35563e08e2a14b58c29cdfb8d2))
* **emails:** unify invitation template variables for SMS and email contexts ([48c716b](https://github.com/LindemannRock/craft-campaign-manager/commit/48c716b1ec87baf41d7b062b537bedba697d6c88))
* **exceptions:** add SendBatchFailedException for handling batch failures ([991554d](https://github.com/LindemannRock/craft-campaign-manager/commit/991554d1730805bde5023774ef8e52b0a54d68ad))
* **git-hooks:** add pre-commit hook for ECS and PHPStan checks ([5288543](https://github.com/LindemannRock/craft-campaign-manager/commit/5288543ebbc4bdfd07ee7b090addd2ae97081f42))
* **i18n:** add error message for failed campaign batches in multiple languages ([72fc2f6](https://github.com/LindemannRock/craft-campaign-manager/commit/72fc2f64b292c9cb865c691ac581f55d8ae0c6d4))
* **i18n:** add Japanese translations for campaign management features ([ee9670a](https://github.com/LindemannRock/craft-campaign-manager/commit/ee9670aafe725f778a28981c18c8e1a4b4ff20d1))
* **i18n:** add translation issue template for reporting translations ([9ed4a5e](https://github.com/LindemannRock/craft-campaign-manager/commit/9ed4a5ef77e8f0d0e24bdf4e5de550b45578b83a))
* **i18n:** add translations for campaign disabled message in multiple languages ([cb50827](https://github.com/LindemannRock/craft-campaign-manager/commit/cb508275379f0109aa979f131a23aba7a540036a))
* **i18n:** update SMS invitation messages for multiple languages ([8e78629](https://github.com/LindemannRock/craft-campaign-manager/commit/8e786295c245324176530706fa43f6f59c3023ea))
* **integration:** enhance SMS Manager integration with default provider and sender ID handling ([5c64fc3](https://github.com/LindemannRock/craft-campaign-manager/commit/5c64fc372d29ac3ce258c9a2526d70bb8b9556c9))
* **migrations:** add indexes for improved query performance in recipients table ([fe247a1](https://github.com/LindemannRock/craft-campaign-manager/commit/fe247a153945ef4e6f0d2fdd06b6020fc84ad481))
* **queue:** optimize recipient fetching and enhance error logging in SendBatchJob ([14bf712](https://github.com/LindemannRock/craft-campaign-manager/commit/14bf712541738cc881b65b1f6863d5511d7d9a3c))
* **recipients:** enhance recipient deletion handling and logging ([420abf8](https://github.com/LindemannRock/craft-campaign-manager/commit/420abf82f09702258ffa92177fa1a4ea3d23e3ad))
* **recipients:** enhance recipient listing with search, filtering, and pagination ([fb7a7eb](https://github.com/LindemannRock/craft-campaign-manager/commit/fb7a7eb72d573f866a3542aee500c62a1b96ad78))
* **recipients:** optimize campaign retrieval for export by pre-fetching ([6a7742d](https://github.com/LindemannRock/craft-campaign-manager/commit/6a7742de2619097f45d17a215e5f8c712695647e))
* **recipients:** optimize recipient data fetching to reduce N+1 queries ([9f46e03](https://github.com/LindemannRock/craft-campaign-manager/commit/9f46e031fbf02ff202b1cda255248616666b497c))
* **recipients:** preserve original CSV filename in import preview ([66640d4](https://github.com/LindemannRock/craft-campaign-manager/commit/66640d48518cde6401a083153ca1eaec05fe22c9))
* **recipients:** stream sample CSV download inline for fresh installs ([b2ddcd8](https://github.com/LindemannRock/craft-campaign-manager/commit/b2ddcd8deb5ddfc8588a29a5275c682757a68279))
* **sms:** unify SMS provider handling and improve country validation ([1372c45](https://github.com/LindemannRock/craft-campaign-manager/commit/1372c45aab40ac0f82bad1c5a1e98f9c41462826))
* **tests:** add integration tests for PhoneHelper sanitization and validation ([c5c4aad](https://github.com/LindemannRock/craft-campaign-manager/commit/c5c4aad4679a97c3c58fbb26f44e930394bfe3fb))


### Fixed

* correct phpstan configuration path in phpstan.neon ([fa155bf](https://github.com/LindemannRock/craft-campaign-manager/commit/fa155bf854b3c4bf9963cb978554eac29088ce07))
* **i18n:** remove deprecated plugin name translations from multiple locales ([52db4df](https://github.com/LindemannRock/craft-campaign-manager/commit/52db4df105af5638bcb2c18b29c121a7d1cea498))
* **integration:** resolve sender ID to handle for campaign usage reporting ([94dfd2f](https://github.com/LindemannRock/craft-campaign-manager/commit/94dfd2f431c16adc57ce92cfd8a5a8bf78507449))
* **phone:** validate phone number input for letters before sanitization ([463e89d](https://github.com/LindemannRock/craft-campaign-manager/commit/463e89d2ea5e9bbc2a0f3c971026e1c0016d2e7c))

## [5.10.3](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.10.2...v5.10.3) - 2026-05-10


### Bug Fixes

* **campaigns:** correct pagination links in campaign edit page ([1b69461](https://github.com/LindemannRock/craft-campaign-manager/commit/1b69461d59f86d343bcfd47862ecf8159eb025ba))
* **responses:** correct base URL handling in filter URL prefix ([f8ee7e5](https://github.com/LindemannRock/craft-campaign-manager/commit/f8ee7e567e8232e97f960acf2db6f3e7a25c7b16))
* **responses:** update event handling for responses table to support AJAX loading ([9207983](https://github.com/LindemannRock/craft-campaign-manager/commit/920798372edab16987a390f77ebc3f052aa1e1af))

## [5.10.2](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.10.1...v5.10.2) - 2026-05-09


### Bug Fixes

* **analytics:** update date range retrieval and improve chart initialization ([e1cceb9](https://github.com/LindemannRock/craft-campaign-manager/commit/e1cceb999c41da1e9261eaf6c5df77afa640dfe3))

## [5.10.1](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.10.0...v5.10.1) - 2026-05-09


### Bug Fixes

* **campaigns:** ensure Chart.js is available before dispatching event ([4224510](https://github.com/LindemannRock/craft-campaign-manager/commit/4224510dca80eb10d63ea19dd5c48864f7be7a0b))

## [5.10.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.9.1...v5.10.0) - 2026-05-09


### Features

* **campaigns:** add lazy-loading for analytics and responses tabs in campaign edit page ([2e13cdd](https://github.com/LindemannRock/craft-campaign-manager/commit/2e13cdd7dbcb22463ce215223e58b09ae19857b4))
* **recipients:** add pagination and limit options for recipient queries ([a780221](https://github.com/LindemannRock/craft-campaign-manager/commit/a780221ee666058be292765ed6e4f4c630f37a4a))

## [5.9.1](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.9.0...v5.9.1) - 2026-05-09


### Bug Fixes

* update plugin description and keywords for clarity ([d10169a](https://github.com/LindemannRock/craft-campaign-manager/commit/d10169a6d37970cfcaf772340e5e2b041b6dfc27))

## [5.9.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.8.2...v5.9.0) - 2026-05-09


### Features

* add issue templates for bug reports, feature requests, and questions ([70b7e8b](https://github.com/LindemannRock/craft-campaign-manager/commit/70b7e8b6960e560eb80495cad683c1df553ad4d8))

## [5.8.2](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.8.1...v5.8.2) - 2026-05-06


### Bug Fixes

* **recipients:** remove unnecessary form tags in add recipient template ([a64e719](https://github.com/LindemannRock/craft-campaign-manager/commit/a64e71908d05706146cb8c29d84e4526a49984c5))
* **translations:** correct translations for various languages ([264bb02](https://github.com/LindemannRock/craft-campaign-manager/commit/264bb0285219e447197940f26f2fab2e14c9d217))

## [5.8.1](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.8.0...v5.8.1) - 2026-04-25


### Bug Fixes

* **analytics:** localDateExpression usage for recipient dates ([b2d0c1f](https://github.com/LindemannRock/craft-campaign-manager/commit/b2d0c1f4a0c52a84e804ee6a781308becefc0797))

## [5.8.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.7.4...v5.8.0) - 2026-04-25


### Features

* **analytics:** add export scope update functionality for NPS charts ([b96b398](https://github.com/LindemannRock/craft-campaign-manager/commit/b96b398c500fb7b18e4d9ff974af38342a623658))
* **analytics:** add NPS export functionality with validation and filename generation ([e5e12a4](https://github.com/LindemannRock/craft-campaign-manager/commit/e5e12a42e061ab82d08b426d1710267a25f22054))
* **analytics:** add NPS tab with distribution and trend charts ([c0da2e0](https://github.com/LindemannRock/craft-campaign-manager/commit/c0da2e081d67aa02897f13a0e0857d8c94ddeba7))
* **analytics:** add ratings tab with NPS, star, and emoji analytics ([807eeae](https://github.com/LindemannRock/craft-campaign-manager/commit/807eeaede62bd6c6328c1e673fcb56a730f58c30))
* **analytics:** add support for rating distribution and trend types ([1d86b2f](https://github.com/LindemannRock/craft-campaign-manager/commit/1d86b2f5db391b994dc75893e19853801c67ec48))
* **analytics:** enhance ratings export functionality with summary and breakdown sections ([89c1a99](https://github.com/LindemannRock/craft-campaign-manager/commit/89c1a994b2b1df1fb793801ea79eda5b77bcc09c))
* **i18n:** add new translations for NPS data export messages ([48b59c1](https://github.com/LindemannRock/craft-campaign-manager/commit/48b59c1590223faaa375410daf502cac2ae5ab01))
* **i18n:** add new translations for ratings analytics in multiple languages ([85acaec](https://github.com/LindemannRock/craft-campaign-manager/commit/85acaec386d2bef83a49349c1fbc6c8b99787a66))
* **i18n:** add new translations for ratings and related data ([88faccb](https://github.com/LindemannRock/craft-campaign-manager/commit/88faccb69b4627c01b05652e6fa70ce83adf808c))
* **i18n:** add NPS translations for multiple languages ([f4ecf32](https://github.com/LindemannRock/craft-campaign-manager/commit/f4ecf3264f02c459c0247df9561a9a87aa3ce4a3))


### Bug Fixes

* **analytics:** update submission date logic to use dateCreated ([e98c72a](https://github.com/LindemannRock/craft-campaign-manager/commit/e98c72af22a403e45a32b61a1ab1394c7d9679d3))
* apply config overrides through shared settings helper ([501dc05](https://github.com/LindemannRock/craft-campaign-manager/commit/501dc057162500ff9adb3c8c63c95af1c613b616))
* drop PAT requirement for release-please — use built-in GITHUB_TOKEN ([a1b0e8f](https://github.com/LindemannRock/craft-campaign-manager/commit/a1b0e8f5f42972c205d975a189404ba4f8970410))
* **i18n:** remove outdated NPS-related strings from translations ([7b9296b](https://github.com/LindemannRock/craft-campaign-manager/commit/7b9296bee51f48a2fa92e7dec074e81006211c5c))

## [5.7.4](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.7.3...v5.7.4) - 2026-04-06


### Bug Fixes

* campaign element check and remove unused settings ([9b3a052](https://github.com/LindemannRock/craft-campaign-manager/commit/9b3a052835284656f3ab474dc404472c20a9b9cb))

## [5.7.3](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.7.2...v5.7.3) - 2026-04-06


### Bug Fixes

* **invite.twig:** update invitation template documentation and logic ([c61b439](https://github.com/LindemannRock/craft-campaign-manager/commit/c61b439f6d29f22de440aee7746bb1a858de880f))

## [5.7.2](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.7.1...v5.7.2) - 2026-04-06


### Bug Fixes

* **CampaignManager:** correct invitation route handling in event rules ([39212b5](https://github.com/LindemannRock/craft-campaign-manager/commit/39212b5a7e1beb460d85ded684a9402c247ab17e))
* **RecipientsController:** handle MultiOptionsFieldData and SingleOptionFieldData correctly ([5ab3d9c](https://github.com/LindemannRock/craft-campaign-manager/commit/5ab3d9c0ea68511d9d1dee91bce11e0f09dd9421))

## [5.7.1](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.7.0...v5.7.1) - 2026-04-05


### Bug Fixes

* **AnalyticsService:** correct siteId usage in recipient query ([5b418b7](https://github.com/LindemannRock/craft-campaign-manager/commit/5b418b7ee844b4fdb7c8cf80cabe1b2b007843f9))

## [5.7.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.6.0...v5.7.0) - 2026-04-02


### Features

* **Analytics:** add site information to campaign stats and overview table ([1d5cf2d](https://github.com/LindemannRock/craft-campaign-manager/commit/1d5cf2da6d6db0b79a6882a7f8afae4f1ddfe379))


### Bug Fixes

* **CampaignManager:** read-only settings page accessibility flag ([1db49c4](https://github.com/LindemannRock/craft-campaign-manager/commit/1db49c4d317192683c9372f175d9aea3a9abbb83))

## [5.6.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.5.4...v5.6.0) - 2026-04-02


### Features

* **CampaignManager:** add install experience configuration ([d28acba](https://github.com/LindemannRock/craft-campaign-manager/commit/d28acba0b3dec019b4d3f9dee225e5deb44c1ef3))
* **icons:** add new SVG icons for campaign manager ([1ff303b](https://github.com/LindemannRock/craft-campaign-manager/commit/1ff303bee71efc58c01cea7191055c25eaf6eddc))


### Bug Fixes

* **CampaignManagerVariable:** improve CKE config handling and validation ([855dac9](https://github.com/LindemannRock/craft-campaign-manager/commit/855dac97c541162f8da5a4bb64f01e3850022975))
* install experience text to use Craft translation ([d1f3f55](https://github.com/LindemannRock/craft-campaign-manager/commit/d1f3f552be14211222cb057ea9392707947fcf20))


### Miscellaneous Chores

* **assets:** update build configuration and package management ([d42c7f2](https://github.com/LindemannRock/craft-campaign-manager/commit/d42c7f229be28d2d28e62f75e975d7cd563adb8e))

## [5.5.4](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.5.3...v5.5.4) - 2026-03-04


### Bug Fixes

* **jobs:** implement QueueTtrTrait in job classes ([4ce38d1](https://github.com/LindemannRock/craft-campaign-manager/commit/4ce38d118d4e1b428b2e70f94c8c2969b704bfa3))
* **SettingsController:** enhance settings validation and error handling ([77ec426](https://github.com/LindemannRock/craft-campaign-manager/commit/77ec426c98c489abe011531e5d7232cc626ff763))

## [5.5.3](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.5.2...v5.5.3) - 2026-02-23


### Bug Fixes

* **CampaignManager:** add no-op setSettings method for clarity ([06df6b7](https://github.com/LindemannRock/craft-campaign-manager/commit/06df6b7cbcf2d276c75e6ab1c08e2d303052287a))
* **RecipientsController:** strip formula escape prefix from CSV import values ([81fb33a](https://github.com/LindemannRock/craft-campaign-manager/commit/81fb33ae1665107ac6695b95304eb1f736b817d8))

## [5.5.2](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.5.1...v5.5.2) - 2026-02-17


### Bug Fixes

* **RecipientsService:** update key name for invitation data ([55cc08b](https://github.com/LindemannRock/craft-campaign-manager/commit/55cc08b33c51a6fb6ce3696c259d0d7d63516f49))

## [5.5.1](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.5.0...v5.5.1) - 2026-02-17


### Bug Fixes

* **RecipientsService:** handle errors when rendering invitation message ([fa222dd](https://github.com/LindemannRock/craft-campaign-manager/commit/fa222ddae5a664116fac057e6357b25d2888aaf9))


### Miscellaneous Chores

* update .gitignore for improved file management ([3500cd2](https://github.com/LindemannRock/craft-campaign-manager/commit/3500cd2d5182d397cefb28a2d57e049edd6383cc))

## [5.5.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.4.1...v5.5.0) - 2026-02-16


### Features

* add Run Campaign action to index and edit page, replace recipient count with sent/pending columns ([129ca25](https://github.com/LindemannRock/craft-campaign-manager/commit/129ca25284f483055a17491542605430bd17ec18))
* fix nested permission pattern — remove viewCampaigns and viewRecipients ([7f10f6c](https://github.com/LindemannRock/craft-campaign-manager/commit/7f10f6cce60131d3cf11e379225a8aadf4b0b9b6))


### Bug Fixes

* permissions heading, export security, campaign filter duplicates, and pluginName config override ([87f1ef1](https://github.com/LindemannRock/craft-campaign-manager/commit/87f1ef165e1daeb02580be1a26169867d0ccb5e6))


### Miscellaneous Chores

* add .gitattributes with export-ignore for Packagist distribution ([16ae6d8](https://github.com/LindemannRock/craft-campaign-manager/commit/16ae6d8f2546f610bffaa190f10b34477af2bedc))
* switch to Craft License for commercial release ([9c591c2](https://github.com/LindemannRock/craft-campaign-manager/commit/9c591c274d5a62b80274d2cee3a935bf74c6b4d7))

## [5.4.1](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.4.0...v5.4.1) - 2026-02-07


### Bug Fixes

* **ActivityLogsController, AnalyticsService, Campaign:** replace DateTimeHelper with DateFormatHelper for date formatting ([fb386f1](https://github.com/LindemannRock/craft-campaign-manager/commit/fb386f1dee4d0f51603f6780d9435bbbd6a6ea48))

## [5.4.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.3.0...v5.4.0) - 2026-02-05


### Features

* **activity-logs:** implement activity logging functionality ([b09f4b0](https://github.com/LindemannRock/craft-campaign-manager/commit/b09f4b0e7835854182c79996f71dda11347d91ac))
* **activity-logs:** implement activity logging service and integrate with controllers ([b19d7e3](https://github.com/LindemannRock/craft-campaign-manager/commit/b19d7e31684ae31ef41a8e6979a64324269d849b))
* **campaign:** add actions for managing recipients ([015dc0e](https://github.com/LindemannRock/craft-campaign-manager/commit/015dc0eac91e14df4f3866d1614ff3f6a491d58f))
* **recipients:** enhance recipient management with new permissions and form updates ([ce15b6d](https://github.com/LindemannRock/craft-campaign-manager/commit/ce15b6d8d3ec40f9ec0c3d386d952ad84d951c2b))


### Bug Fixes

* **CampaignManager:** correct version annotation in getCpSections method ([4b389de](https://github.com/LindemannRock/craft-campaign-manager/commit/4b389deca6464f0161ab612ba3472d62428ca949))
* correct timezone handling in analytics date grouping ([b295636](https://github.com/LindemannRock/craft-campaign-manager/commit/b29563617d038bddd653ecd12bc22fec36f310f7))


### Miscellaneous Chores

* **package.json:** update package name to scoped version and add private flag ([41909bd](https://github.com/LindemannRock/craft-campaign-manager/commit/41909bd4987cd794eb8835915e28641e2d1bab63))

## [5.3.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.2.0...v5.3.0) - 2026-01-29


### Features

* **campaign:** add method to check submissions across all sites ([8e9bc32](https://github.com/LindemannRock/craft-campaign-manager/commit/8e9bc32058ce9de32b4a26dea62172c485ab4055))

## [5.2.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.1.0...v5.2.0) - 2026-01-28


### Features

* add import preview template for recipient import process ([c58c16d](https://github.com/LindemannRock/craft-campaign-manager/commit/c58c16d01c5c93c353cd6fce5b5a1564c3ca8a11))
* **config:** add initial configuration file for Campaign Manager plugin ([11008f2](https://github.com/LindemannRock/craft-campaign-manager/commit/11008f24ad793b5bcc957577cedba7fd4f6f93b3))
* **recipients:** update language handling to site mapping ([ec32f95](https://github.com/LindemannRock/craft-campaign-manager/commit/ec32f95cd4113fbb9f792f66e5aee8b2077bf338))

## [5.1.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.0.0...v5.1.0) - 2026-01-27


### Features

* **customers:** add global customer index and export functionality ([bc7230e](https://github.com/LindemannRock/craft-campaign-manager/commit/bc7230e5ab30797db4497de7f68d07cf616b1789))

## 5.0.0 - 2026-01-27


### Features

* initial Campaign Manager plugin implementation ([bd81801](https://github.com/LindemannRock/craft-campaign-manager/commit/bd81801ec9764f65b5bef5a0863a5791da2736fe))
