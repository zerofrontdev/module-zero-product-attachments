<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\ProductAttachments\Test\Unit\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\ProductAttachments\Model\Attachments;
use Zero\ProductAttachments\Model\Config;
use Zero\ProductAttachments\Model\FileTypes;

/**
 * An attachment is checked before its file is copied, and a copy whose row
 * can't be written is removed again.
 */
#[AllowMockObjectsWithoutExpectations]
class AttachmentsTest extends TestCase
{
    private string $tmpFile;
    private Mysql&MockObject $connection;
    private DriverInterface&MockObject $driver;
    private WriteInterface&MockObject $media;
    private Filesystem&MockObject $filesystem;
    private EventManager&MockObject $events;

    protected function setUp(): void
    {
        // finfo must say application/pdf.
        $this->tmpFile = (string) tempnam(sys_get_temp_dir(), 'zero-attachment-');
        file_put_contents($this->tmpFile, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        $this->connection = $this->createMock(Mysql::class);
        $select = $this->createStub(Select::class);
        $select->method($this->anything())->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->driver = $this->createMock(DriverInterface::class);
        $this->media = $this->createMock(WriteInterface::class);
        $this->media->method('getDriver')->willReturn($this->driver);
        $this->media->method('getAbsolutePath')->willReturnCallback(static fn ($path = null) => '/pub/media/' . $path);
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->filesystem->method('getDirectoryWrite')->willReturn($this->media);
        $this->events = $this->createMock(EventManager::class);
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testAnUnknownStoreViewIsRefusedBeforeAnyCopy(): void
    {
        $this->filesystem->expects($this->never())->method('getDirectoryWrite');
        $this->driver->expects($this->never())->method('copy');
        $this->connection->expects($this->never())->method('insert');

        try {
            $this->attachments()->add(10, 99, 'Manual', 0, $this->tmpFile, 'Manual.pdf');
            $this->fail('an unknown store view was accepted');
        } catch (LocalizedException $e) {
            $this->assertSame('This store view doesn\'t exist.', $e->getMessage());
        }
    }

    public function testAFailedInsertRemovesTheCopiedFile(): void
    {
        $copied = null;
        $this->driver->expects($this->once())->method('copy')
            ->willReturnCallback(function ($source, $destination) use (&$copied) {
                $this->assertSame($this->tmpFile, $source);
                $copied = $destination;
                return true;
            });
        $this->connection->method('insert')->willThrowException(new \RuntimeException('a foreign key constraint fails'));
        $this->media->expects($this->once())->method('create')->with($this->matchesRegularExpression('#^zero/attachments/[0-9a-f]{16}\z#'));
        $removed = null;
        $this->media->expects($this->once())->method('delete')->willReturnCallback(function ($path) use (&$removed) {
            $removed = $path;
            return true;
        });
        $this->events->expects($this->never())->method('dispatch');

        try {
            $this->attachments()->add(10, 1, 'Manual', 0, $this->tmpFile, 'Manual (EN).pdf');
            $this->fail('a row that was not written was reported saved');
        } catch (LocalizedException $e) {
            $this->assertSame('The attachment was not saved: a foreign key constraint fails', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }

        $this->assertMatchesRegularExpression('#^zero/attachments/[0-9a-f]{16}\z#', (string) $removed);
        $this->assertSame('/pub/media/' . $removed . '/manual-en.pdf', $copied, 'the folder of the copy goes');
    }

    public function testAllStoreViewsIsAcceptedAndTheProductPageCleaned(): void
    {
        $this->driver->expects($this->once())->method('copy')->willReturn(true);
        $this->connection->expects($this->once())->method('insert')->with(
            'zero_product_attachment',
            $this->callback(static fn (array $row) => $row['product_id'] === 10 && $row['store_id'] === 0 && $row['file_name'] === 'manual.pdf')
        );
        $this->connection->method('lastInsertId')->willReturn('7');
        $this->media->expects($this->never())->method('delete');
        $this->events->expects($this->once())->method('dispatch')->with(
            'clean_cache_by_tags',
            $this->callback(static fn (array $data) => $data['object']->getIdentities() === ['cat_p_10'])
        );

        $this->assertSame(7, $this->attachments(false)->add(10, 0, '', 0, $this->tmpFile, 'manual.pdf'));
    }

    public function testAnUpdateToAnUnknownStoreViewIsRefused(): void
    {
        $this->connection->expects($this->never())->method('update');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This store view doesn\'t exist.');

        $this->attachments()->update(5, 'Manual', 99, 0);
    }

    public function testAnUpdateToAKnownStoreViewIsWritten(): void
    {
        $this->connection->method('fetchRow')->willReturn(['attachment_id' => 5, 'product_id' => 10, 'title' => 'Old']);
        $this->connection->expects($this->once())->method('update')->with(
            'zero_product_attachment',
            ['title' => 'Old', 'store_id' => 1, 'sort_order' => 3],
            ['attachment_id = ?' => 5]
        );

        $this->attachments()->update(5, ' ', 1, 3);
    }

    /**
     * Store view 1 exists; any other is unknown. With $storeChecked false the
     * store manager must not be asked at all.
     */
    private function attachments(bool $storeChecked = true): Attachments
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $config = $this->createStub(Config::class);
        $config->method('maxSize')->willReturn(10 * 1024 * 1024);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($storeChecked ? $this->atLeastOnce() : $this->never())->method('getStore')
            ->willReturnCallback(function ($storeId) {
                if ((int) $storeId !== 1) {
                    throw new NoSuchEntityException(__('The store that was requested wasn\'t found.'));
                }
                return $this->createStub(StoreInterface::class);
            });

        return new Attachments(
            $resource,
            $this->filesystem,
            new FileTypes(),
            $config,
            $this->createStub(CacheInterface::class),
            $this->events,
            $storeManager
        );
    }
}
