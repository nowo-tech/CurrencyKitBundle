# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/currency-kit-bundle`  
**Last audited**: 2026-11-20 (0.1.0)

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **159**.

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Application/BatchValidator.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Application/BundledCurrencyCatalogue.php` | Money and currencies | FR-MONEY-001, FR-MONEY-002 |
| `src/Application/CurrencyConverter.php` | Conversion | FR-CONVERT-001, FR-CONVERT-002, FR-WORKER-001 |
| `src/Application/CurrencyConverterInterface.php` | Conversion | FR-CONVERT-001, FR-CONVERT-002, FR-WORKER-001 |
| `src/Application/CurrencyKitSettings.php` | Bundle and DI | FR-BUNDLE-002 |
| `src/Application/ManualRateService.php` | Manual rates | FR-MANUAL-001, FR-MANUAL-002 |
| `src/Application/ManualRateServiceInterface.php` | Manual rates | FR-MANUAL-001, FR-MANUAL-002 |
| `src/Application/NullResolvedRateCache.php` | Conversion | FR-CONVERT-001, FR-CONVERT-002, FR-WORKER-001 |
| `src/Application/RateMaintenance.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Application/RateMemo.php` | Conversion | FR-CONVERT-001, FR-CONVERT-002, FR-WORKER-001 |
| `src/Application/RateResolver.php` | Conversion | FR-CONVERT-001, FR-CONVERT-002, FR-WORKER-001 |
| `src/Application/RateSourceRegistry.php` | Sources and outbound | FR-SOURCE-001, FR-SOURCE-002 |
| `src/Application/RateSynchronizer.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Application/RateSynchronizerInterface.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Application/ReferenceRateReader.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Application/ReferenceRateReaderInterface.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Application/TargetCalendar.php` | Conversion | FR-CONVERT-001, FR-CONVERT-002, FR-WORKER-001 |
| `src/Bridge/Scheduler/ColdStartTrigger.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Bridge/Scheduler/CurrencyKitScheduleProvider.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Domain/Audit/AuditEntry.php` | Audit | FR-AUDIT-001 |
| `src/Domain/Conversion/ConversionOptions.php` | Conversion | FR-CONVERT-001, FR-CONVERT-002, FR-WORKER-001 |
| `src/Domain/Conversion/ConvertedAmount.php` | Conversion | FR-CONVERT-001, FR-CONVERT-002, FR-WORKER-001 |
| `src/Domain/Conversion/OnStale.php` | Conversion | FR-CONVERT-001, FR-CONVERT-002, FR-WORKER-001 |
| `src/Domain/Conversion/RateLeg.php` | Conversion | FR-CONVERT-001, FR-CONVERT-002, FR-WORKER-001 |
| `src/Domain/Conversion/ResolvedRate.php` | Conversion | FR-CONVERT-001, FR-CONVERT-002, FR-WORKER-001 |
| `src/Domain/Currency/CurrencyCode.php` | Money and currencies | FR-MONEY-001, FR-MONEY-002 |
| `src/Domain/Currency/Iso4217Table.php` | Money and currencies | FR-MONEY-001, FR-MONEY-002 |
| `src/Domain/Event/ManualRateCreated.php` | Events | FR-EVENT-001 |
| `src/Domain/Event/ManualRateEnded.php` | Events | FR-EVENT-001 |
| `src/Domain/Event/ManualRateUpdated.php` | Events | FR-EVENT-001 |
| `src/Domain/Event/ReferenceRateChanged.php` | Events | FR-EVENT-001 |
| `src/Domain/Event/ReferenceRateSyncFailed.php` | Events | FR-EVENT-001 |
| `src/Domain/Event/ReferenceRatesPublished.php` | Events | FR-EVENT-001 |
| `src/Domain/Event/ReferenceRatesStale.php` | Events | FR-EVENT-001 |
| `src/Domain/Exception/AccessDeniedException.php` | Errors | FR-ERROR-001, FR-ACCESS-001 |
| `src/Domain/Exception/CurrencyKitException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/InvalidAmountException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/InvalidCurrencyCodeException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/InvalidValueException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/ManualRateNotFoundException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/ManualRateOverlapException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/ManualRateRefusedException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/OutboundDisabledException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/RateNotAvailableException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/RateSourceResponseException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/RateSourceUnavailableException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/StaleManualRateException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/StaleRateException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/SyncInterruptedException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Manual/ActorRef.php` | Manual rates | FR-MANUAL-001, FR-MANUAL-002 |
| `src/Domain/Manual/ManualRateInput.php` | Manual rates | FR-MANUAL-001, FR-MANUAL-002 |
| `src/Domain/Manual/ManualRateStatus.php` | Manual rates | FR-MANUAL-001, FR-MANUAL-002 |
| `src/Domain/Manual/ManualRateView.php` | Manual rates | FR-MANUAL-001, FR-MANUAL-002 |
| `src/Domain/Money/Decimal.php` | Money and currencies | FR-MONEY-001, FR-MONEY-002 |
| `src/Domain/Money/Money.php` | Money and currencies | FR-MONEY-001, FR-MONEY-002 |
| `src/Domain/Money/RoundingMode.php` | Money and currencies | FR-MONEY-001, FR-MONEY-002 |
| `src/Domain/Port/CurrencyCatalogueInterface.php` | Money and currencies | FR-MONEY-001, FR-MONEY-002 |
| `src/Domain/Port/CurrencyKitAccessCheckerInterface.php` | Access | FR-ACCESS-001, FR-ACCESS-002 |
| `src/Domain/Port/CurrencyKitActorResolverInterface.php` | Access | FR-ACCESS-001, FR-ACCESS-002 |
| `src/Domain/Port/CurrencyKitAuditSinkInterface.php` | Audit | FR-AUDIT-001 |
| `src/Domain/Port/IdGeneratorInterface.php` | Storage | FR-STORAGE-001, FR-STORAGE-002 |
| `src/Domain/Port/OutboundFetcherInterface.php` | Sources and outbound | FR-SOURCE-001, FR-SOURCE-002 |
| `src/Domain/Port/RateFileReaderInterface.php` | Sources and outbound | FR-SOURCE-001, FR-SOURCE-002 |
| `src/Domain/Port/RateSourceInterface.php` | Sources and outbound | FR-SOURCE-001, FR-SOURCE-002 |
| `src/Domain/Port/RateStoreInterface.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Domain/Port/ResolvedRateCacheInterface.php` | Conversion | FR-CONVERT-001, FR-CONVERT-002, FR-WORKER-001 |
| `src/Domain/Port/RunLockInterface.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Domain/Port/TargetCalendarInterface.php` | Conversion | FR-CONVERT-001, FR-CONVERT-002, FR-WORKER-001 |
| `src/Domain/Port/WriteRunnerInterface.php` | Concurrency | FR-CONCURRENCY-001 |
| `src/Domain/Rate/DayRates.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Domain/Rate/FetchFeed.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Domain/Rate/FetchLimits.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Domain/Rate/FetchRequest.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Domain/Rate/FetchedBody.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Domain/Rate/PublicationInfo.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Domain/Rate/RateBatch.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Domain/Rate/RateChange.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Domain/Rate/RateDate.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Domain/Rate/RateOrigin.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Domain/Rate/RateValue.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Domain/Rate/SourceKey.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Domain/Rate/SourceLabel.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Domain/Sync/ChangedValuePolicy.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Domain/Sync/DayResult.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Domain/Sync/SyncOptions.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Domain/Sync/SyncOutcome.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Domain/Sync/SyncReport.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Infrastructure/Cache/Psr6ResolvedRateCache.php` | Conversion | FR-CONVERT-001, FR-CONVERT-002, FR-WORKER-001 |
| `src/Infrastructure/Dbal/ConnectionOnlyRegistry.php` | Concurrency | FR-CONCURRENCY-001 |
| `src/Infrastructure/Dbal/DatabaseRunLock.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Infrastructure/Dbal/DbalRateStore.php` | Storage | FR-STORAGE-001, FR-STORAGE-002 |
| `src/Infrastructure/Dbal/DbalSql.php` | Storage | FR-STORAGE-001, FR-STORAGE-002 |
| `src/Infrastructure/Dbal/DbalWriteRunner.php` | Concurrency | FR-CONCURRENCY-001 |
| `src/Infrastructure/Dbal/TableNames.php` | Storage | FR-STORAGE-001, FR-STORAGE-002 |
| `src/Infrastructure/Doctrine/Entity/ManualRate.php` | Storage | FR-STORAGE-001, FR-STORAGE-002 |
| `src/Infrastructure/Doctrine/Entity/RateChangeRecord.php` | Storage | FR-STORAGE-001, FR-STORAGE-002 |
| `src/Infrastructure/Doctrine/Entity/RatePublication.php` | Storage | FR-STORAGE-001, FR-STORAGE-002 |
| `src/Infrastructure/Doctrine/Entity/ReferenceRate.php` | Storage | FR-STORAGE-001, FR-STORAGE-002 |
| `src/Infrastructure/Doctrine/Entity/SyncLock.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Infrastructure/Doctrine/SchemaChecker.php` | Storage | FR-STORAGE-001, FR-STORAGE-002 |
| `src/Infrastructure/Doctrine/SchemaMetadataListener.php` | Storage | FR-STORAGE-001, FR-STORAGE-002 |
| `src/Infrastructure/Http/GuardedHttpFetcher.php` | Sources and outbound | FR-SOURCE-001, FR-SOURCE-002 |
| `src/Infrastructure/Source/Csv/CsvRateParser.php` | Sources and outbound | FR-SOURCE-001, FR-SOURCE-002 |
| `src/Infrastructure/Source/Ecb/EcbXmlParser.php` | Sources and outbound | FR-SOURCE-001, FR-SOURCE-002 |
| `src/Infrastructure/Source/Ecb/EcbXmlSource.php` | Sources and outbound | FR-SOURCE-001, FR-SOURCE-002 |
| `src/Infrastructure/Source/Frankfurter/FrankfurterCompatibleSource.php` | Sources and outbound | FR-SOURCE-001, FR-SOURCE-002 |
| `src/Infrastructure/Source/Frankfurter/FrankfurterJsonParser.php` | Sources and outbound | FR-SOURCE-001, FR-SOURCE-002 |
| `src/Infrastructure/Source/LocalRateFileReader.php` | Sources and outbound | FR-SOURCE-001, FR-SOURCE-002 |
| `src/Infrastructure/Source/Manual/ManualRateSource.php` | Manual rates | FR-MANUAL-001, FR-MANUAL-002 |
| `src/Infrastructure/Support/PsrLogAuditSink.php` | Audit | FR-AUDIT-001 |
| `src/Infrastructure/Support/UuidV7IdGenerator.php` | Storage | FR-STORAGE-001, FR-STORAGE-002 |
| `src/Infrastructure/Symfony/Command/ActorForgetCommand.php` | Access | FR-ACCESS-003 |
| `src/Infrastructure/Symfony/Command/CommandSupport.php` | Console | FR-CLI-001 |
| `src/Infrastructure/Symfony/Command/ConvertCommand.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Infrastructure/Symfony/Command/ManualEndCommand.php` | Manual rates | FR-MANUAL-001, FR-MANUAL-002 |
| `src/Infrastructure/Symfony/Command/ManualListCommand.php` | Manual rates | FR-MANUAL-001, FR-MANUAL-002 |
| `src/Infrastructure/Symfony/Command/ManualSetCommand.php` | Manual rates | FR-MANUAL-001, FR-MANUAL-002 |
| `src/Infrastructure/Symfony/Command/PruneCommand.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Infrastructure/Symfony/Command/RatesBackfillCommand.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Infrastructure/Symfony/Command/RatesChangesCommand.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Infrastructure/Symfony/Command/RatesImportCommand.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Infrastructure/Symfony/Command/RatesShowCommand.php` | Rates and reading | FR-RATE-001, FR-RATE-002 |
| `src/Infrastructure/Symfony/Command/RatesSyncCommand.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Infrastructure/Symfony/Command/SchemaCheckCommand.php` | Console | FR-CLI-001 |
| `src/Infrastructure/Symfony/DependencyInjection/Compiler/CurrencyKitPortsPass.php` | Bundle and DI | FR-BUNDLE-001, FR-ACCESS-002 |
| `src/Infrastructure/Symfony/DependencyInjection/ConfigurationTree.php` | Bundle and DI | FR-BUNDLE-002 |
| `src/Infrastructure/Symfony/DependencyInjection/Requirement.php` | Bundle and DI | FR-BUNDLE-002 |
| `src/Infrastructure/Symfony/DependencyInjection/ServiceDefinitions.php` | Bundle and DI | FR-BUNDLE-001, FR-WORKER-001 |
| `src/Infrastructure/Symfony/Event/AbstractAccessCheckEvent.php` | Access | FR-ACCESS-001, FR-ACCESS-002 |
| `src/Infrastructure/Symfony/Event/CurrencyAccessCheckEvent.php` | Access | FR-ACCESS-001, FR-ACCESS-002 |
| `src/Infrastructure/Symfony/Event/CurrencyKitEvents.php` | Access | FR-ACCESS-001, FR-ACCESS-002 |
| `src/Infrastructure/Symfony/Event/ManualRateAccessCheckEvent.php` | Access | FR-ACCESS-001, FR-ACCESS-002 |
| `src/Infrastructure/Symfony/Event/SyncAccessCheckEvent.php` | Access | FR-ACCESS-001, FR-ACCESS-002 |
| `src/Infrastructure/Symfony/EventListener/RateMemoResetListener.php` | Worker mode | FR-WORKER-001 |
| `src/Infrastructure/Symfony/Messenger/SyncReferenceRatesHandler.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Infrastructure/Symfony/Messenger/SyncReferenceRatesMessage.php` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Infrastructure/Symfony/Security/AccessChannel.php` | Access | FR-ACCESS-001, FR-ACCESS-002 |
| `src/Infrastructure/Symfony/Security/AllowAllCurrencyKitAccessChecker.php` | Access | FR-ACCESS-001, FR-ACCESS-002 |
| `src/Infrastructure/Symfony/Security/ConfigurableCurrencyKitAccessChecker.php` | Access | FR-ACCESS-001, FR-ACCESS-002 |
| `src/Infrastructure/Symfony/Security/CurrencyKitGuard.php` | Access | FR-ACCESS-001, FR-ACCESS-002 |
| `src/Infrastructure/Symfony/Security/CurrencyKitPermission.php` | Access | FR-ACCESS-001, FR-ACCESS-002 |
| `src/Infrastructure/Symfony/Security/SecurityActorResolver.php` | Access | FR-ACCESS-001, FR-ACCESS-002 |
| `src/Infrastructure/Symfony/Twig/ReferenceMoneyExtension.php` | Twig | FR-TWIG-001 |
| `src/NowoCurrencyKitBundle.php` | Bundle and DI | FR-BUNDLE-001 |
| `src/Resources/config/doctrine/ManualRate.orm.xml` | Storage | FR-STORAGE-001, FR-STORAGE-002 |
| `src/Resources/config/doctrine/RateChangeRecord.orm.xml` | Storage | FR-STORAGE-001, FR-STORAGE-002 |
| `src/Resources/config/doctrine/RatePublication.orm.xml` | Storage | FR-STORAGE-001, FR-STORAGE-002 |
| `src/Resources/config/doctrine/ReferenceRate.orm.xml` | Storage | FR-STORAGE-001, FR-STORAGE-002 |
| `src/Resources/config/doctrine/SyncLock.orm.xml` | Sync and maintenance | FR-SYNC-001, FR-SYNC-002 |
| `src/Resources/translations/NowoCurrencyKitBundle.ca.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoCurrencyKitBundle.de.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoCurrencyKitBundle.en.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoCurrencyKitBundle.es.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoCurrencyKitBundle.eu.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoCurrencyKitBundle.fr.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoCurrencyKitBundle.gl.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoCurrencyKitBundle.it.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoCurrencyKitBundle.nl.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoCurrencyKitBundle.pt.yaml` | Translations | FR-I18N-001 |

## Coverage summary

| Spec section | Files |
| --- | --- |
| Access | 14 |
| Audit | 3 |
| Bundle and DI | 6 |
| Concurrency | 3 |
| Console | 2 |
| Conversion | 14 |
| Errors | 15 |
| Events | 7 |
| Manual rates | 10 |
| Money and currencies | 7 |
| Rates and reading | 19 |
| Sources and outbound | 11 |
| Storage | 15 |
| Sync and maintenance | 21 |
| Translations | 10 |
| Twig | 1 |
| Worker mode | 1 |
| **Total** | **159** |

Mapped files: **159 / 159** (100%). Excluded from the inventory: `tests/` and `docs/`.
