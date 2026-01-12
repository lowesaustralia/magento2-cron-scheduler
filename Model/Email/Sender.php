<?php declare(strict_types=1);

namespace KiwiCommerce\CronScheduler\Model\Email;

use KiwiCommerce\CronScheduler\Model\ResourceModel\Schedule\CollectionFactory;
use Magento\Cron\Model\Schedule;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\Template\SenderResolverInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends Cron Scheduler alert emails from a cron-safe model (no Adminhtml controller).
 */
class Sender
{
    /**
     * Recipient email config path
     */
    private const XML_PATH_EMAIL_RECIPIENT = 'cronscheduler/general/cronscheduler_admin_email';

    /**
     * Recipient email enable/disable status
     */
    private const XML_PATH_EMAIL_ENABLE_STATUS = 'cronscheduler/general/cronscheduler_email_enabled';

    /**
     * Email template identifier (configured in module)
     */
    private const EMAIL_TEMPLATE_ID = 'cronscheduler_email_template';

    /**
     * Flag value written back to cron_schedule after email is sent
     */
    private const IS_MAIL_STATUS = 1;

    /** @var CollectionFactory */
    private $scheduleCollectionFactory;

    /** @var TransportBuilder */
    private $transportBuilder;

    /** @var StateInterface */
    private $inlineTranslation;

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var SenderResolverInterface */
    private $senderResolver;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        CollectionFactory $scheduleCollectionFactory,
        TransportBuilder $transportBuilder,
        StateInterface $inlineTranslation,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        SenderResolverInterface $senderResolver,
        LoggerInterface $logger
    ) {
        $this->scheduleCollectionFactory = $scheduleCollectionFactory;
        $this->transportBuilder = $transportBuilder;
        $this->inlineTranslation = $inlineTranslation;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->senderResolver = $senderResolver;
        $this->logger = $logger;
    }

    /**
     * Entrypoint used by etc/crontab.xml
     */
    public function execute(): void
    {
        // Check if cron email alerts are enabled.
        $enabled = (bool)$this->scopeConfig->getValue(
            self::XML_PATH_EMAIL_ENABLE_STATUS,
            ScopeInterface::SCOPE_STORE
        );
        if (!$enabled) {
            return;
        }

        // Build datasets.
        $errorMessages = $this->getFatalErrorOfJobcode(); // collection
        $missedJobs    = $this->getMissedCronJob();       // collection

        // Resolve recipients.
        $receiverEmailConfig = (string)$this->scopeConfig->getValue(
            self::XML_PATH_EMAIL_RECIPIENT,
            ScopeInterface::SCOPE_STORE
        );
        $receiverEmailIds = $receiverEmailConfig
            ? array_values(array_filter(array_map('trim', explode(',', $receiverEmailConfig))))
            : [];

        // Only send when we have recipients and something to report.
        if (!empty($receiverEmailIds)
            && ((!empty($errorMessages->getData())) || (!empty($missedJobs->getData())))) {
            try {
                $from = $this->senderResolver->resolve('general');

                $items = [
                    'errorMessages' => $errorMessages,
                    'missedJobs'    => $missedJobs,
                ];

                $this->sendEmailStatus($receiverEmailIds, $from, $items);
                $this->updateMailStatus($items);

                $this->logger->info('[CronScheduler] Alert email sent and mail status updated.');
            } catch (\Throwable $e) {
                // Log and rethrow to mark cron schedule as error.
                $this->logger->critical($e);
                throw $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * Update is_mail_sent=1 for the affected schedule rows.
     *
     * @param array{errorMessages:\Magento\Cron\Model\ResourceModel\Schedule\Collection, missedJobs:\Magento\Cron\Model\ResourceModel\Schedule\Collection} $emailItems
     */
    private function updateMailStatus(array $emailItems): void
    {
        // Error messages
        if (!empty($emailItems['errorMessages'])) {
            foreach ($emailItems['errorMessages'] as $errorMessage) {
                $collection = $this->scheduleCollectionFactory->create();
                $filters = [
                    'schedule_id' => (int)$errorMessage['max_id'],
                    'job_code'    => $errorMessage['job_code'],
                    'status'      => Schedule::STATUS_ERROR,
                ];
                $collection->updateMailStatusByJobCode(['is_mail_sent' => self::IS_MAIL_STATUS], $filters);
            }
        }

        // Missed jobs
        if (!empty($emailItems['missedJobs'])) {
            foreach ($emailItems['missedJobs'] as $missedJob) {
                $collection = $this->scheduleCollectionFactory->create();
                $filters = [
                    'schedule_id' => (int)$missedJob['max_id'],
                    'job_code'    => $missedJob['job_code'],
                    'status'      => Schedule::STATUS_MISSED,
                ];
                $collection->updateMailStatusByJobCode(['is_mail_sent' => self::IS_MAIL_STATUS], $filters);
            }
        }
    }

    /**
     * Get aggregated MISSed cron jobs (max id, count by job_code, not yet mailed).
     *
     * @return \KiwiCommerce\CronScheduler\Model\ResourceModel\Schedule\Collection
     */
    private function getMissedCronJob()
    {
        $collection = $this->scheduleCollectionFactory->create();

        $collection->getSelect()
            ->where('status = "' . Schedule::STATUS_MISSED . '"')
            ->where('is_mail_sent is NULL')
            ->reset('columns')
            ->columns(['job_code', 'MAX(schedule_id) as max_id', 'COUNT(schedule_id) as totalmissed'])
            ->group(['job_code']);

        return $collection;
    }

    /**
     * Get aggregated ERROR entries per job_code (max id, error message, not yet mailed).
     *
     * @return \KiwiCommerce\CronScheduler\Model\ResourceModel\Schedule\Collection
     */
    private function getFatalErrorOfJobcode()
    {
        $collection = $this->scheduleCollectionFactory->create();

        $collection->getSelect()
            ->where('status = "' . Schedule::STATUS_ERROR . '"')
            ->where('error_message is not NULL')
            ->where('is_mail_sent is NULL')
            ->reset('columns')
            ->columns(['job_code', 'error_message', 'MAX(schedule_id) as max_id'])
            ->group(['job_code']);

        return $collection;
    }

    /**
     * Compose and send the email using the configured template.
     *
     * @param string[] $to
     * @param array    $from
     * @param array    $items
     * @return $this
     * @throws MailException
     */
    private function sendEmailStatus(array $to, array $from, array $items): self
    {
        $templateOptions = [
            // Using FRONTEND keeps parity with original controller.
            // No controller execution here, so design plugin won’t be triggered.
            'area'  => Area::AREA_FRONTEND,
            'store' => (int)$this->storeManager->getStore()->getId(),
        ];

        $templateVars = [
            'store' => $this->storeManager->getStore(),
            'items' => $items,
        ];

        $this->inlineTranslation->suspend();

        $this->transportBuilder
            ->setTemplateIdentifier(self::EMAIL_TEMPLATE_ID)
            ->setTemplateOptions($templateOptions)
            ->setTemplateVars($templateVars)
            ->setFrom($from)
            ->addTo($to);

        $transport = $this->transportBuilder->getTransport();
        $transport->sendMessage();

        $this->inlineTranslation->resume();

        return $this;
    }
}
