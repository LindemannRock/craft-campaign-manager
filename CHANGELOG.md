# Changelog

## [5.9.1](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.9.0...v5.9.1) (2026-05-09)


### Bug Fixes

* update plugin description and keywords for clarity ([d10169a](https://github.com/LindemannRock/craft-campaign-manager/commit/d10169a6d37970cfcaf772340e5e2b041b6dfc27))

## [5.9.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.8.2...v5.9.0) (2026-05-09)


### Features

* add issue templates for bug reports, feature requests, and questions ([70b7e8b](https://github.com/LindemannRock/craft-campaign-manager/commit/70b7e8b6960e560eb80495cad683c1df553ad4d8))

## [5.8.2](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.8.1...v5.8.2) (2026-05-06)


### Bug Fixes

* **recipients:** remove unnecessary form tags in add recipient template ([a64e719](https://github.com/LindemannRock/craft-campaign-manager/commit/a64e71908d05706146cb8c29d84e4526a49984c5))
* **translations:** correct translations for various languages ([264bb02](https://github.com/LindemannRock/craft-campaign-manager/commit/264bb0285219e447197940f26f2fab2e14c9d217))

## [5.8.1](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.8.0...v5.8.1) (2026-04-25)


### Bug Fixes

* **analytics:** localDateExpression usage for recipient dates ([b2d0c1f](https://github.com/LindemannRock/craft-campaign-manager/commit/b2d0c1f4a0c52a84e804ee6a781308becefc0797))

## [5.8.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.7.4...v5.8.0) (2026-04-25)


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

## [5.7.4](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.7.3...v5.7.4) (2026-04-06)


### Bug Fixes

* campaign element check and remove unused settings ([9b3a052](https://github.com/LindemannRock/craft-campaign-manager/commit/9b3a052835284656f3ab474dc404472c20a9b9cb))

## [5.7.3](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.7.2...v5.7.3) (2026-04-06)


### Bug Fixes

* **invite.twig:** update invitation template documentation and logic ([c61b439](https://github.com/LindemannRock/craft-campaign-manager/commit/c61b439f6d29f22de440aee7746bb1a858de880f))

## [5.7.2](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.7.1...v5.7.2) (2026-04-06)


### Bug Fixes

* **CampaignManager:** correct invitation route handling in event rules ([39212b5](https://github.com/LindemannRock/craft-campaign-manager/commit/39212b5a7e1beb460d85ded684a9402c247ab17e))
* **RecipientsController:** handle MultiOptionsFieldData and SingleOptionFieldData correctly ([5ab3d9c](https://github.com/LindemannRock/craft-campaign-manager/commit/5ab3d9c0ea68511d9d1dee91bce11e0f09dd9421))

## [5.7.1](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.7.0...v5.7.1) (2026-04-05)


### Bug Fixes

* **AnalyticsService:** correct siteId usage in recipient query ([5b418b7](https://github.com/LindemannRock/craft-campaign-manager/commit/5b418b7ee844b4fdb7c8cf80cabe1b2b007843f9))

## [5.7.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.6.0...v5.7.0) (2026-04-02)


### Features

* **Analytics:** add site information to campaign stats and overview table ([1d5cf2d](https://github.com/LindemannRock/craft-campaign-manager/commit/1d5cf2da6d6db0b79a6882a7f8afae4f1ddfe379))


### Bug Fixes

* **CampaignManager:** read-only settings page accessibility flag ([1db49c4](https://github.com/LindemannRock/craft-campaign-manager/commit/1db49c4d317192683c9372f175d9aea3a9abbb83))

## [5.6.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.5.4...v5.6.0) (2026-04-02)


### Features

* **CampaignManager:** add install experience configuration ([d28acba](https://github.com/LindemannRock/craft-campaign-manager/commit/d28acba0b3dec019b4d3f9dee225e5deb44c1ef3))
* **icons:** add new SVG icons for campaign manager ([1ff303b](https://github.com/LindemannRock/craft-campaign-manager/commit/1ff303bee71efc58c01cea7191055c25eaf6eddc))


### Bug Fixes

* **CampaignManagerVariable:** improve CKE config handling and validation ([855dac9](https://github.com/LindemannRock/craft-campaign-manager/commit/855dac97c541162f8da5a4bb64f01e3850022975))
* install experience text to use Craft translation ([d1f3f55](https://github.com/LindemannRock/craft-campaign-manager/commit/d1f3f552be14211222cb057ea9392707947fcf20))


### Miscellaneous Chores

* **assets:** update build configuration and package management ([d42c7f2](https://github.com/LindemannRock/craft-campaign-manager/commit/d42c7f229be28d2d28e62f75e975d7cd563adb8e))

## [5.5.4](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.5.3...v5.5.4) (2026-03-04)


### Bug Fixes

* **jobs:** implement QueueTtrTrait in job classes ([4ce38d1](https://github.com/LindemannRock/craft-campaign-manager/commit/4ce38d118d4e1b428b2e70f94c8c2969b704bfa3))
* **SettingsController:** enhance settings validation and error handling ([77ec426](https://github.com/LindemannRock/craft-campaign-manager/commit/77ec426c98c489abe011531e5d7232cc626ff763))

## [5.5.3](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.5.2...v5.5.3) (2026-02-23)


### Bug Fixes

* **CampaignManager:** add no-op setSettings method for clarity ([06df6b7](https://github.com/LindemannRock/craft-campaign-manager/commit/06df6b7cbcf2d276c75e6ab1c08e2d303052287a))
* **RecipientsController:** strip formula escape prefix from CSV import values ([81fb33a](https://github.com/LindemannRock/craft-campaign-manager/commit/81fb33ae1665107ac6695b95304eb1f736b817d8))

## [5.5.2](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.5.1...v5.5.2) (2026-02-17)


### Bug Fixes

* **RecipientsService:** update key name for invitation data ([55cc08b](https://github.com/LindemannRock/craft-campaign-manager/commit/55cc08b33c51a6fb6ce3696c259d0d7d63516f49))

## [5.5.1](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.5.0...v5.5.1) (2026-02-17)


### Bug Fixes

* **RecipientsService:** handle errors when rendering invitation message ([fa222dd](https://github.com/LindemannRock/craft-campaign-manager/commit/fa222ddae5a664116fac057e6357b25d2888aaf9))


### Miscellaneous Chores

* update .gitignore for improved file management ([3500cd2](https://github.com/LindemannRock/craft-campaign-manager/commit/3500cd2d5182d397cefb28a2d57e049edd6383cc))

## [5.5.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.4.1...v5.5.0) (2026-02-16)


### Features

* add Run Campaign action to index and edit page, replace recipient count with sent/pending columns ([129ca25](https://github.com/LindemannRock/craft-campaign-manager/commit/129ca25284f483055a17491542605430bd17ec18))
* fix nested permission pattern — remove viewCampaigns and viewRecipients ([7f10f6c](https://github.com/LindemannRock/craft-campaign-manager/commit/7f10f6cce60131d3cf11e379225a8aadf4b0b9b6))


### Bug Fixes

* permissions heading, export security, campaign filter duplicates, and pluginName config override ([87f1ef1](https://github.com/LindemannRock/craft-campaign-manager/commit/87f1ef165e1daeb02580be1a26169867d0ccb5e6))


### Miscellaneous Chores

* add .gitattributes with export-ignore for Packagist distribution ([16ae6d8](https://github.com/LindemannRock/craft-campaign-manager/commit/16ae6d8f2546f610bffaa190f10b34477af2bedc))
* switch to Craft License for commercial release ([9c591c2](https://github.com/LindemannRock/craft-campaign-manager/commit/9c591c274d5a62b80274d2cee3a935bf74c6b4d7))

## [5.4.1](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.4.0...v5.4.1) (2026-02-07)


### Bug Fixes

* **ActivityLogsController, AnalyticsService, Campaign:** replace DateTimeHelper with DateFormatHelper for date formatting ([fb386f1](https://github.com/LindemannRock/craft-campaign-manager/commit/fb386f1dee4d0f51603f6780d9435bbbd6a6ea48))

## [5.4.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.3.0...v5.4.0) (2026-02-05)


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

## [5.3.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.2.0...v5.3.0) (2026-01-29)


### Features

* **campaign:** add method to check submissions across all sites ([8e9bc32](https://github.com/LindemannRock/craft-campaign-manager/commit/8e9bc32058ce9de32b4a26dea62172c485ab4055))

## [5.2.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.1.0...v5.2.0) (2026-01-28)


### Features

* add import preview template for recipient import process ([c58c16d](https://github.com/LindemannRock/craft-campaign-manager/commit/c58c16d01c5c93c353cd6fce5b5a1564c3ca8a11))
* **config:** add initial configuration file for Campaign Manager plugin ([11008f2](https://github.com/LindemannRock/craft-campaign-manager/commit/11008f24ad793b5bcc957577cedba7fd4f6f93b3))
* **recipients:** update language handling to site mapping ([ec32f95](https://github.com/LindemannRock/craft-campaign-manager/commit/ec32f95cd4113fbb9f792f66e5aee8b2077bf338))

## [5.1.0](https://github.com/LindemannRock/craft-campaign-manager/compare/v5.0.0...v5.1.0) (2026-01-27)


### Features

* **customers:** add global customer index and export functionality ([bc7230e](https://github.com/LindemannRock/craft-campaign-manager/commit/bc7230e5ab30797db4497de7f68d07cf616b1789))

## 5.0.0 (2026-01-27)


### Features

* initial Campaign Manager plugin implementation ([bd81801](https://github.com/LindemannRock/craft-campaign-manager/commit/bd81801ec9764f65b5bef5a0863a5791da2736fe))
