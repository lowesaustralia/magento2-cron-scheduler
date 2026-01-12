<?php declare(strict_types=1);

namespace KiwiCommerce\CronScheduler\Controller\Adminhtml\Cron;

use KiwiCommerce\CronScheduler\Model\Email\Sender as EmailSender;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Psr\Log\LoggerInterface;

/**
 * Admin endpoint (optional) to trigger Cron Scheduler email alerts manually.
 * Now delegates to the model to avoid design initialization in CLI/cron context.
 */
class Sendemail extends Action
{
    /** @var EmailSender */
    private $emailSender;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        EmailSender $emailSender,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->emailSender = $emailSender;
        $this->logger = $logger;
    }

    /**
     * Execute and report to admin UI (if invoked from backend).
     *
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|null
     */
    public function execute()
    {
        try {
            $this->emailSender->execute();
            // Optional success message for backend users
            $this->messageManager->addSuccessMessage(__('Cron Scheduler alert email processed.'));
        } catch (\Throwable $e) {
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(__('Cron Scheduler alert email failed: %1', $e->getMessage()));
        }

        // Redirect back if invoked via backend; otherwise return nothing
        if ($this->getRequest()->isXmlHttpRequest()) {
            return null;
        }

        return $this->_redirect('adminhtml/system_config/edit/section/cronscheduler');
    }
}
