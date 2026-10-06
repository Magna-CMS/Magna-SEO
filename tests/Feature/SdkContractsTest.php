<?php

declare(strict_types=1);

namespace Magna\Seo\Tests\Feature;

use Magna\Contracts\DecoratesDeliveryResponse;
use Magna\Contracts\ExtendsEntryForm;
use Magna\Contracts\ExtendsEntryTable;
use Magna\Contracts\RegistersAdminResources;
use Magna\Contracts\RegistersCommands;
use Magna\Contracts\RegistersSettingsPages;
use Magna\Seo\SeoPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The SDK contracts this plugin implements must exist before the plugin class
 * can even be loaded.
 *
 * This exists because of a specific, real fragility: `ExtendsEntryTable` was
 * added to the plugin SDK, and the SDK's source directory is not under version
 * control. If that file is ever lost — a fresh SDK checkout, a
 * `composer reinstall` from a source copy that predates it — every class in this
 * plugin implementing it becomes unloadable, and the panel fatals rather than
 * degrading.
 *
 * A missing contract should therefore fail here, loudly, in CI, rather than in
 * production. Deliberately a plain TestCase: it must not need a booted
 * application, because the failure it guards against is precisely one that stops
 * the application booting.
 */
final class SdkContractsTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function contracts(): array
    {
        return [
            [DecoratesDeliveryResponse::class],
            [ExtendsEntryForm::class],
            [ExtendsEntryTable::class],
            [RegistersAdminResources::class],
            [RegistersCommands::class],
            [RegistersSettingsPages::class],
        ];
    }

    #[DataProvider('contracts')]
    public function test_every_contract_the_plugin_implements_is_available(string $contract): void
    {
        $this->assertTrue(
            interface_exists($contract),
            "The SDK contract [{$contract}] is missing. If this is ExtendsEntryTable, the plugin SDK "
            .'source at magna-plugin-sdk has lost it — see docs/DASHBOARD-PLAN.md.',
        );
    }

    public function test_the_plugin_declares_exactly_the_contracts_it_is_wired_for(): void
    {
        $implemented = (new ReflectionClass(SeoPlugin::class))->getInterfaceNames();

        foreach (array_column(self::contracts(), 0) as $contract) {
            $this->assertContains(
                $contract,
                $implemented,
                "SeoPlugin no longer implements [{$contract}]; the wiring that depends on it is now dead.",
            );
        }
    }
}
