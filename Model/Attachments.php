<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\ProductAttachments\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Product attachments: the rows and their files under
 * pub/media/zero/attachments/<random>/<name>. A change cleans the product's
 * page from the page cache (built-in or Varnish).
 */
class Attachments
{
    public const DIR = 'zero/attachments';
    private const TABLE = 'zero_product_attachment';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Filesystem $filesystem,
        private readonly FileTypes $fileTypes,
        private readonly Config $config,
        private readonly CacheInterface $cache,
        private readonly EventManager $eventManager,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * A store view's attachments of a product (its own and All Store Views'), in order.
     *
     * @return array<int, array>
     */
    public function forProduct(int $productId, int $storeId): array
    {
        $connection = $this->resource->getConnection();
        return $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->where('product_id = ?', $productId)
                ->where('store_id IN (?)', array_unique([0, $storeId]))
                ->order(['sort_order ASC', 'attachment_id ASC'])
        );
    }

    /**
     * Every attachment with its product's SKU and name, newest first, or a product's.
     *
     * @return array<int, array>
     */
    public function all(string $sku = '', int $limit = 200): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->resource->getTableName(self::TABLE)])
            ->join(['p' => $this->resource->getTableName('catalog_product_entity')], 'p.entity_id = a.product_id', ['sku'])
            ->order(['p.sku ASC', 'a.sort_order ASC', 'a.attachment_id ASC'])
            ->limit($limit);
        if ($sku !== '') {
            $select->where('p.sku = ?', $sku);
        }
        return $connection->fetchAll($select);
    }

    public function productId(string $sku): ?int
    {
        $connection = $this->resource->getConnection();
        $id = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_entity'), ['entity_id'])
                ->where('sku = ?', trim($sku))
        );
        return $id === false ? null : (int) $id;
    }

    /**
     * Attaches an uploaded file (a PHP temporary file). Everything is checked
     * before the file is copied; if the row can't be written, the copy goes.
     *
     * @throws LocalizedException
     */
    public function add(int $productId, int $storeId, string $title, int $sortOrder, string $tmpFile, string $fileName): int
    {
        $this->assertStore($storeId);
        $fileName = $this->cleanName($fileName);
        if (!is_uploaded_file($tmpFile) && !is_file($tmpFile)) {
            throw new LocalizedException(__('The file didn\'t arrive. Is it larger than the server allows?'));
        }
        $size = (int) filesize($tmpFile);
        if ($size <= 0 || $size > $this->config->maxSize()) {
            throw new LocalizedException(__('The file is empty or larger than %1.', $this->fileTypes->size($this->config->maxSize())));
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmpFile);
        if (!$this->fileTypes->accepts($fileName, $mime)) {
            throw new LocalizedException(__(
                'This kind of file can\'t be attached (%1). Allowed: %2.',
                $mime,
                implode(', ', $this->fileTypes->extensions())
            ));
        }
        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $relative = self::DIR . '/' . bin2hex(random_bytes(8)) . '/' . $fileName;
        $media->create(dirname($relative));
        $media->getDriver()->copy($tmpFile, $media->getAbsolutePath($relative));
        $connection = $this->resource->getConnection();
        try {
            $connection->insert($this->resource->getTableName(self::TABLE), [
                'product_id' => $productId,
                'store_id' => $storeId,
                'title' => mb_substr(trim($title) !== '' ? trim($title) : pathinfo($fileName, PATHINFO_FILENAME), 0, 255),
                'file' => $relative,
                'file_name' => $fileName,
                'size' => $size,
                'sort_order' => $sortOrder,
            ]);
            $id = (int) $connection->lastInsertId($this->resource->getTableName(self::TABLE));
        } catch (\Throwable $e) {
            // No file without its row (an unknown product, say).
            $media->delete(dirname($relative));
            throw new LocalizedException(__('The attachment was not saved: %1', $e->getMessage()), $e);
        }
        $this->clean($productId);
        return $id;
    }

    /**
     * @throws LocalizedException
     */
    public function update(int $attachmentId, string $title, int $storeId, int $sortOrder): void
    {
        $this->assertStore($storeId);
        $row = $this->get($attachmentId);
        if (!$row) {
            return;
        }
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            ['title' => mb_substr(trim($title) ?: (string) $row['title'], 0, 255), 'store_id' => $storeId, 'sort_order' => $sortOrder],
            ['attachment_id = ?' => $attachmentId]
        );
        $this->clean((int) $row['product_id']);
    }

    public function delete(int $attachmentId): void
    {
        $row = $this->get($attachmentId);
        if (!$row) {
            return;
        }
        $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE), ['attachment_id = ?' => $attachmentId]);
        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $dir = dirname((string) $row['file']);
        if (str_starts_with($dir, self::DIR . '/') && $media->isExist($dir)) {
            $media->delete($dir);
        }
        $this->clean((int) $row['product_id']);
    }

    public function get(int $attachmentId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->resource->getTableName(self::TABLE))->where('attachment_id = ?', $attachmentId)
        );
        return $row ?: null;
    }

    /**
     * All Store Views (0) or a store view that exists.
     *
     * @throws LocalizedException
     */
    private function assertStore(int $storeId): void
    {
        if ($storeId === 0) {
            return;
        }
        try {
            $this->storeManager->getStore($storeId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('This store view doesn\'t exist.'));
        }
    }

    /** A name safe in a URL and on any disk: "Manual (EN) v2.pdf" => "manual-en-v2.pdf". */
    public function cleanName(string $fileName): string
    {
        $extension = $this->fileTypes->extension($fileName);
        $base = strtolower((string) pathinfo($fileName, PATHINFO_FILENAME));
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', $base), '-');
        return ($base !== '' ? mb_substr($base, 0, 80) : 'file') . ($extension !== '' ? '.' . $extension : '');
    }

    private function clean(int $productId): void
    {
        $tags = [Product::CACHE_TAG . '_' . $productId];
        $object = new class ($tags) implements IdentityInterface {
            public function __construct(private readonly array $tags)
            {
            }

            public function getIdentities()
            {
                return $this->tags;
            }
        };
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $object]);
        $this->cache->clean($tags);
    }
}
