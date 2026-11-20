<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Core\Tests\Unit\Dataset;

use OpenColibri\Starter\Core\Dataset\Dataset;
use OpenColibri\Starter\Core\Dataset\DatasetDirectories;
use OpenColibri\Starter\Core\Dataset\Datasets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DatasetsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/core-datasets-' . bin2hex(random_bytes(4));
        $this->writeDataset('built-in/shared', 'Built-in');
        $this->writeDataset('built-in/wine', 'Wine', image: true);
        $this->writeDataset('editable/shared', 'Editable');
        $this->writeDataset('editable/coffee', 'Coffee');
        mkdir($this->directory . '/editable/not-a-dataset', 0o775, true);

        DatasetDirectories::register('test-editable', fn(): string => $this->directory . '/editable', builtIn: false);
        DatasetDirectories::register('test-built-in', $this->directory . '/built-in');
    }

    protected function tearDown(): void
    {
        DatasetDirectories::unregister('test-editable');
        DatasetDirectories::unregister('test-built-in');
        (new Filesystem())->remove($this->directory);
    }

    public function testBuiltInDatasetsWinOverEditableOnes(): void
    {
        $dataset = Datasets::get('shared');

        static::assertTrue($dataset->builtIn);
        static::assertSame('Built-in', $dataset->name());
        static::assertFalse(Datasets::get('coffee')->builtIn);
        static::assertNull(Datasets::find('not-a-dataset'));
    }

    public function testUnknownDatasetFails(): void
    {
        $this->expectExceptionMessage('Unknown dataset "missing"');

        Datasets::get('missing');
    }

    public function testDescribesADataset(): void
    {
        $description = Datasets::get('wine')->describe();

        static::assertSame('Wine', $description['name']);
        static::assertSame('A shop', $description['description']);
        static::assertSame(2, $description['productCount']);
        static::assertSame(1, $description['imageCount']);
        static::assertSame(1, $description['collectionCount']);
    }

    public function testResolvesOnlyStoredImagesInsideTheDataset(): void
    {
        $dataset = Datasets::get('wine');

        static::assertSame($dataset->path . '/media/bottle.webp', $dataset->imageFile('media/bottle.webp'));
        static::assertNull($dataset->imageFile('media/missing.webp'));
        static::assertNull($dataset->imageFile('../wine/media/bottle.webp'));
        static::assertNull($dataset->imageFile('https://example.com/bottle.webp'));
        static::assertFalse(Dataset::isLocalImage('//cdn.example.com/a.webp'));
    }

    private function writeDataset(string $path, string $name, bool $image = false): void
    {
        $directory = $this->directory . '/' . $path;
        mkdir($directory . '/media', 0o775, true);
        file_put_contents($directory . '/dataset.yaml', "name: $name\ndescription: A shop\n");
        file_put_contents($directory . '/collections.yaml', "collections:\n  - name: Red\n");
        file_put_contents($directory . '/products.yaml', <<<YAML
            products:
              - title: Bottle
                collection_name: Red
                price_eur: 12
                image: media/bottle.webp
              - title: Magnum
                collection_name: Red
                price_eur: 30
                image: https://example.com/magnum.webp
            YAML);

        if ($image) {
            file_put_contents($directory . '/media/bottle.webp', 'webp');
        }
    }
}
