<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\ProductAttachments\Controller\Adminhtml\Attachment;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Zero\ProductAttachments\Model\Attachments;

/**
 * A row's title, store view and sort order.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Zero_ProductAttachments::attachments';

    public function __construct(Context $context, private readonly Attachments $attachments)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $this->attachments->update(
                (int) $this->getRequest()->getParam('id'),
                (string) $this->getRequest()->getParam('title'),
                (int) $this->getRequest()->getParam('store'),
                (int) $this->getRequest()->getParam('sort_order')
            );
            $this->messageManager->addSuccessMessage(__('Saved.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        $sku = trim((string) $this->getRequest()->getParam('sku'));
        return $this->resultRedirectFactory->create()->setPath('*/*/index', $sku !== '' ? ['sku' => $sku] : []);
    }
}
