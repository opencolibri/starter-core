<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Core\Tests\Unit\Util;

use Castor\Container;
use OpenColibri\Starter\Core\App;
use OpenColibri\Starter\Core\Util\Javascript;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(Javascript::class)]
final class JavascriptTest extends TestCase
{
    private string $root;

    private Filesystem $filesystem;

    private App $app;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/castor-javascript-' . uniqid('', true);
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->root);

        $container = (new \ReflectionClass(Container::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Container::class, 'fs'))->setValue($container, $this->filesystem);
        Container::set($container);

        $this->app = new App('app', $this->root);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testRemoveVendorImportsDropsThePackageImportsFromBothEntrypoints(): void
    {
        // The entrypoints of Sylius-Standard 2.3.
        $this->filesystem->dumpFile($this->root . '/assets/shop/entrypoint.js', <<<'JS'
            import '@vendor/sylius/mollie-plugin/assets/shop/entrypoint';
            import '@vendor/flux-se/sylius-stripe-plugin/assets/shop/entrypoint';
            import '@vendor/sylius/adyen-plugin/assets/shop/entrypoint';
            import './bootstrap.js';

            JS);
        $this->filesystem->dumpFile($this->root . '/assets/admin/entrypoint.js', <<<'JS'
            import '@vendor/sylius/mollie-plugin/assets/admin/entrypoint';
            import '@vendor/flux-se/sylius-stripe-plugin/assets/admin/entrypoint';
            import '@vendor/sylius/refund-plugin/assets/entrypoint';
            import '@vendor/sylius/paypal-plugin/assets/admin/entrypoint';
            import './bootstrap.js';

            JS);

        Javascript::removeVendorImports($this->app, 'sylius/mollie-plugin');
        Javascript::removeVendorImports($this->app, 'sylius/paypal-plugin');

        static::assertSame(<<<'JS'
            import '@vendor/flux-se/sylius-stripe-plugin/assets/shop/entrypoint';
            import '@vendor/sylius/adyen-plugin/assets/shop/entrypoint';
            import './bootstrap.js';

            JS, file_get_contents($this->root . '/assets/shop/entrypoint.js'));
        static::assertSame(<<<'JS'
            import '@vendor/flux-se/sylius-stripe-plugin/assets/admin/entrypoint';
            import '@vendor/sylius/refund-plugin/assets/entrypoint';
            import './bootstrap.js';

            JS, file_get_contents($this->root . '/assets/admin/entrypoint.js'));
    }

    public function testRemoveVendorImportsSkipsAMissingEntrypoint(): void
    {
        $this->filesystem->dumpFile($this->root . '/assets/shop/entrypoint.js', "import './bootstrap.js';\n");

        Javascript::removeVendorImports($this->app, 'sylius/mollie-plugin');

        static::assertSame("import './bootstrap.js';\n", file_get_contents($this->root . '/assets/shop/entrypoint.js'));
        static::assertFileDoesNotExist($this->root . '/assets/admin/entrypoint.js');
    }
}
