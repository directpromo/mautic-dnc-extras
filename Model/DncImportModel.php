<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticDoNotContactExtrasBundle\Model;

use Doctrine\ORM\ORMException;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Helper\PhoneNumberHelper;
use Mautic\CoreBundle\Model\FormModel;
use Mautic\CoreBundle\Model\NotificationModel;
use Mautic\LeadBundle\Exception\ImportDelayedException;
use Mautic\LeadBundle\Exception\ImportFailedException;
use Mautic\LeadBundle\Helper\Progress;
use MauticPlugin\MauticDoNotContactExtrasBundle\Entity\DncImport;
use MauticPlugin\MauticDoNotContactExtrasBundle\Entity\DncImportRepository;
use MauticPlugin\MauticDoNotContactExtrasBundle\Entity\DncListItem;

/**
 * Class DncImportModel.
 */
class DncImportModel extends FormModel
{
    /**
     * @var PathsHelper
     */
    protected $pathsHelper;

    /**
     * @var dncListItemModel
     */
    protected $dncListItemModel;

    /**
     * @var NotificationModel
     */
    protected $notificationModel;

    /**
     * @var CoreParametersHelper
     */
    protected $config;

    /**
     * @var PhoneNumberHelper
     */
    protected $phoneHelper;

    /**
     * ImportModel constructor.
     *
     * @param PathsHelper          $pathsHelper
     * @param DncListItemModel     $dncListItemModel
     * @param NotificationModel    $notificationModel
     * @param CoreParametersHelper $config
     */
    public function __construct(
        PathsHelper $pathsHelper,
        DncListItemModel $dncListItemModel,
        NotificationModel $notificationModel,
        CoreParametersHelper $config
    ) {
        $this->pathsHelper       = $pathsHelper;
        $this->dncListItemModel  = $dncListItemModel;
        $this->notificationModel = $notificationModel;
        $this->config            = $config;
    }

    /**
     * Returns the Import entity which should be processed next.
     *
     * @return DncImport|null
     */
    public function getImportToProcess()
    {
        $result = $this->getRepository()->getImportsWithStatuses([DncImport::QUEUED, DncImport::DELAYED], 1);

        if (isset($result[0]) && $result[0] instanceof DncImport) {
            return $result[0];
        }

        return null;
    }

    /**
     * Compares current number of imports in progress with the limit from the configuration.
     *
     * @return bool
     */
    public function checkParallelImportLimit()
    {
        $parallelImportLimit = $this->getParallelImportLimit();
        $importsInProgress   = $this->getRepository()->countImportsInProgress();

        return !($importsInProgress >= $parallelImportLimit);
    }

    /**
     * Returns parallel import limit from the configuration.
     *
     * @param int $default
     *
     * @return int
     */
    public function getParallelImportLimit(
        $default = 1
    ) {
        return $this->config->getParameter('parallel_import_limit', $default);
    }

    /**
     * Generates a HTML link to the import detail.
     *
     * @param DncImport $import
     *
     * @return string
     */
    public function generateLink(
        DncImport $import
    ) {
        return '<a href="'.$this->router->generate(
                'mautic_dnc_import_action',
                ['objectAction' => 'view', 'objectId' => $import->getId()]
            ).'" data-toggle="ajax">'.$import->getOriginalFile().' ('.$import->getId().')</a>';
    }

    /**
     * Returns import max runtime status from the configuration
     * when max runtime has been exceeded.
     *
     * @param int $default
     *
     * @return int
     */
    public function getImportMaxRuntimeStatus(
        $default = 4
    ) {
        return $this->config->getParameter('import_max_runtime_status', $default);
    }

    /**
     * Check if there are some IN_PROGRESS imports which have exceded maximum runtime.
     * Set those to ghost import status.
     */
    public function setMaxImportsRuntimeStatus()
    {
        $maxImportRuntime = $this->config->getParameter('import_max_runtime', 2);
        $imports          = $this->getRepository()->getGhostImports($maxImportRuntime, 5);

        if (empty($imports)) {
            return null;
        }

        $status   = $this->getImportMaxRuntimeStatus();
        $infoVars = [
            '%limit%' => $maxImportRuntime,
            '%status' => $this->translator->trans('mautic.dnc.import.status.'.$status),
        ];

        /** @var DncImport $import */
        foreach ($imports as $import) {
            $import->setStatus($status)
                ->setStatusInfo($this->translator->trans('mautic.dnc.import.max.runtime.hit', $infoVars));

            if (DncImport::FAILED === $status) {
                $import->removeFile();
            }

            if ($import->getCreatedBy()) {
                $this->notificationModel->addNotification(
                    $this->translator->trans(
                        'mautic.dnc.import.result.info',
                        ['%import%' => $this->generateLink($import)]
                    ),
                    'info',
                    false,
                    $this->translator->trans('mautic.dnc.import.'.$status),
                    'fa-download',
                    null,
                    $this->em->getReference('MauticUserBundle:User', $import->getCreatedBy())
                );
            }
        }

        $this->saveEntities($imports);
    }

    /**
     * Start import. This is meant for the CLI command since it will import
     * the whole file at once.
     *
     * @deprecated in 2.13.0. To be removed in 3.0.0. Use beginImport instead
     *
     * @param DncImport $import
     * @param Progress  $progress
     * @param int       $limit    Number of records to import before delaying the import. 0 will import all
     *
     * @return bool
     */
    public function startImport(
        DncImport $import,
        Progress $progress,
        $limit = 0
    ) {
        try {
            return $this->beginImport($import, $progress, $limit);
        } catch (\Exception $e) {
            $this->logDebug($e->getMessage());

            return false;
        }
    }

    /**
     * Start import. This is meant for the CLI command since it will import
     * the whole file at once.
     *
     * @param DncImport $import
     * @param Progress  $progress
     * @param int       $limit    Number of records to import before delaying the import. 0 will import all
     *
     * @throws ImportFailedException
     * @throws ImportDelayedException
     */
    public function beginImport(
        DncImport $import,
        Progress $progress,
        $limit = 0
    ) {
        $this->setMaxImportsRuntimeStatus();

        if (!$import) {
            $msg = 'import is empty, closing the import process';
            $this->logDebug($msg, $import);
            throw new ImportFailedException($msg);
        }

        if (!$import->canProceed()) {
            $this->saveEntity($import);
            $msg = 'import cannot be processed because '.$import->getStatusInfo();
            $this->logDebug($msg, $import);
            throw new ImportFailedException($msg);
        }

        if (!$this->checkParallelImportLimit()) {
            $info = $this->translator->trans(
                'mautic.dnc.import.parallel.limit.hit',
                ['%limit%' => $this->getParallelImportLimit()]
            );
            $import->setStatus($import::DELAYED)->setStatusInfo($info);
            $this->saveEntity($import);
            $msg = 'import is delayed because parrallel limit was hit. '.$import->getStatusInfo();
            $this->logDebug($msg, $import);
            throw new ImportDelayedException($msg);
        }

        $processed = $import->getProcessedRows();
        $total     = $import->getLineCount();
        $pending   = $total - $processed;

        if ($limit && $limit < $pending) {
            $processed = 0;
            $total     = $limit;
        }

        $progress->setTotal($total);
        $progress->setDone($processed);

        $import->start();

        // Save the start changes so the user could see it
        $this->saveEntity($import);
        $this->logDebug('The background import is about to start', $import);

        try {
            if (!$this->process($import, $progress, $limit)) {
                throw new ImportFailedException($import->getStatusInfo());
            }
        } catch (ORMException $e) {
            // The EntityManager is probably closed. The entity cannot be saved.
            $info = $this->translator->trans(
                'mautic.lead.import.database.exception',
                ['%message%' => $e->getMessage()]
            );

            $import->setStatus($import::DELAYED)->setStatusInfo($info);

            throw new ImportFailedException('Database had been overloaded');
        }

        $import->end();
        $this->logDebug('The background import has ended', $import);

        // Save the end changes so the user could see it
        $this->saveEntity($import);

        // Perhaps implement Import::shouldNotify instead of using Import::canProceed
        if ($import->getCreatedBy() && !$import->canProceed()) {
            $this->notificationModel->addNotification(
                $this->translator->trans(
                    'mautic.dnc.import.result.info',
                    ['%import%' => $this->generateLink($import)]
                ),
                'info',
                false,
                $this->translator->trans('mautic.dnc.import.completed'),
                'fa-download',
                null,
                $this->em->getReference('MauticUserBundle:User', $import->getCreatedBy())
            );
        }
    }

    /**
     * Import the CSV file from configuration in the $import entity.
     *
     * @param DncImport $import
     * @param Progress  $progress
     * @param int       $limit    Number of records to import before delaying the import
     *
     * @return bool
     */
    public function process(
        DncImport $import,
        Progress $progress,
        $limit = 0
    ) {
        //Auto detect line endings for the file to work around MS DOS vs Unix new line characters
        ini_set('auto_detect_line_endings', true);

        try {
            $file = new \SplFileObject($import->getFilePath());
        } catch (\Exception $e) {
            $import->setStatusInfo('SplFileObject cannot read the file. '.$e->getMessage());
            $import->setStatus(DncImport::FAILED);
            $this->logDebug('import cannot be processed because '.$import->getStatusInfo(), $import);

            return false;
        }

        $lastImportedLine = $import->getLastLineImported();
        $headers          = $import->getHeaders();
        $headerCount      = count($headers);
        $config           = $import->getParserConfig();
        $counter          = 0;

        if ($lastImportedLine > 0) {
            // Seek is zero-based line numbering and
            $file->seek($lastImportedLine - 1);
        }

        $lineNumber = $lastImportedLine + 1;
        $this->logDebug('The import is starting on line '.$lineNumber, $import);

        $batchSize = $config['batchlimit'];

        // Convert to field names
        array_walk(
            $headers,
            function (&$val) {
                $val = strtolower(InputHelper::alphanum($val, false, '_'));
            }
        );

        while ($batchSize && !$file->eof()) {
            $data = $file->fgetcsv($config['delimiter'], $config['enclosure'], $config['escape']);
            $import->setLastLineImported($lineNumber);

            // Ignore the header row
            if (1 === $lineNumber) {
                ++$lineNumber;
                continue;
            }

            // Ensure the progress is changing
            ++$lineNumber;
            --$batchSize;
            $progress->increase();

            $errorMessage = null;

            if ($this->isEmptyCsvRow($data)) {
                $errorMessage = 'mautic.dnc.import.error.line_empty';
            }

            if ($this->hasMoreValuesThanColumns($data, $headerCount)) {
                $errorMessage = 'mautic.dnc.import.error.header_mismatch';
            }

            if (!$errorMessage) {
                $data = $this->trimArrayValues($data);
                if (!array_filter($data)) {
                    continue;
                }

                $data = array_combine($headers, $data);

                $is_valid = $this->checkForValidValues($import->getMatchedFields(), $data);

                if ($is_valid) {
                    try {
                        $merged = $this->dncListItemModel->import(
                            $import->getMatchedFields(),
                            $data,
                            $import->getDefault('owner'),
                            $import->getId()
                        );

                        if ($merged) {
                            $this->logDebug('Entity on line '.$lineNumber.' has been updated', $import);
                            $import->increaseUpdatedCount();
                        } else {
                            $this->logDebug('Entity on line '.$lineNumber.' has been created', $import);
                            $import->increaseInsertedCount();
                        }
                    } catch (\Exception $e) {
                        // Email validation likely failed
                        $errorMessage = $e->getMessage();
                    }
                } else {
                    $errorMessage = 'mautic.dnc.import.error.invalid_value';
                }
            }

            if ($errorMessage) {
                $import->increaseIgnoredCount();
                $this->logDebug('Line '.$lineNumber.' error: '.$errorMessage, $import);
            }

            // Release entities in Doctrine's memory to prevent memory leak
            $data = null;
            $this->em->clear(DncListItem::class);

            // Save Import entity once per batch so the user could see the progress
            if (0 === $batchSize && $import->isBackgroundProcess()) {
                $isPublished = $this->getRepository()->getValue($import->getId(), 'is_published');

                if (!$isPublished) {
                    $import->setStatus($import::STOPPED);
                }

                $this->saveEntity($import);

                // Stop the import loop if the import got unpublished
                if (!$isPublished) {
                    $this->logDebug('The import has been unpublished. Stopping the import now.', $import);
                    break;
                }

                $batchSize = $config['batchlimit'];
            }

            ++$counter;
            if ($limit && $counter >= $limit) {
                $import->setStatus($import::DELAYED);
                $this->saveEntity($import);
                break;
            }
        }

        // Close the file
        $file = null;

        return true;
    }

    /**
     * Check if the CSV row has more values than the CSV header has columns.
     * If it is less, generate empty values for the rest of the missing values.
     * If it is more, return true.
     *
     * @param array &$data
     * @param int   $headerCount
     *
     * @return bool
     */
    public function hasMoreValuesThanColumns(
        array &$data,
        $headerCount
    ) {
        $dataCount = count($data);

        if ($headerCount !== $dataCount) {
            $diffCount = ($headerCount - $dataCount);

            if ($diffCount > 0) {
                // Fill in the data with empty string
                $fill = array_fill($dataCount, $diffCount, '');
                $data = $data + $fill;
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * Trim all values in a one dymensional array.
     *
     * @param array $data
     *
     * @return array
     */
    public function trimArrayValues(
        array $data
    ) {
        return array_map('trim', $data);
    }

    /**
     * Decide whether the CSV row is empty.
     *
     * @param mixed $row
     *
     * @return bool
     */
    public function isEmptyCsvRow(
        $row
    ) {
        if (!is_array($row) || empty($row)) {
            return true;
        }

        if (1 === count($row) && ('' === $row[0] || null === $row[0])) {
            return true;
        }

        return !array_filter($row);
    }

    /**
     * Get line chart data of imported rows.
     *
     * @param string    $unit       {@link php.net/manual/en/function.date.php#refsect1-function.date-parameters}
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     * @param string    $dateFormat
     * @param array     $filter
     *
     * @return array
     */
    // public
    // function getImportedRowsLineChartData(
    //     $unit,
    //     \DateTime $dateFrom,
    //     \DateTime $dateTo,
    //     $dateFormat = null,
    //     $filter = []
    // ) {
    //     $filter['object'] = 'import';
    //     $filter['bundle'] = 'donotcontactextras';
    //
    //     // Clear the times for display by minutes
    //     $dateFrom->modify('-1 minute');
    //     $dateFrom->setTime($dateFrom->format('H'), $dateFrom->format('i'), 0);
    //     $dateTo->modify('+1 minute');
    //     $dateTo->setTime($dateTo->format('H'), $dateTo->format('i'), 0);
    //
    //     $query = new ChartQuery($this->em->getConnection(), $dateFrom, $dateTo, $unit);
    //     $chart = new LineChart($unit, $dateFrom, $dateTo, $dateFormat);
    //     $data  = $query->fetchTimeData('lead_event_log', 'date_added', $filter);
    //
    //     $chart->setDataset($this->translator->trans('mautic.lead.import.processed.rows'), $data);
    //
    //     return $chart->render();
    // }

    /**
     * Returns a list of failed rows for the import.
     *
     * @param int $importId
     *
     * @return array|null
     */
    public function getFailedRows(
        $importId = null
    ) {
        if (!$importId) {
            return null;
        }

        // return $this->getEventLogRepository()->getFailedRows($importId, ['select' => 'properties,id']);
    }

    /**
     * @return DncImportRepository
     */
    public function getRepository()
    {
        return $this->em->getRepository('MauticDoNotContactExtrasBundle:DncImport');
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getPermissionBase()
    {
        return 'donotcontactextras:items';
    }

    /**
     * Returns a unique name of a CSV file based on time.
     *
     * @return string
     */
    public function getUniqueFileName()
    {
        return (new DateTimeHelper())->toUtcString('YmdHis').'.csv';
    }

    /**
     * Returns a full path to the import dir.
     *
     * @return string
     */
    public function getImportDir()
    {
        return $this->pathsHelper->getSystemPath('imports', true);
    }

    /**
     * Get a specific entity or generate a new one if id is empty.
     *
     * @param $id
     *
     * @return object|null
     */
    public function getEntity(
        $id = null
    ) {
        if (null === $id) {
            return new DncImport();
        }

        return parent::getEntity($id);
    }

    /**
     * Logs a debug message if in dev environment.
     *
     * @param string    $msg
     * @param DncImport $import
     */
    protected function logDebug(
        $msg,
        DncImport $import = null
    ) {
        if (MAUTIC_ENV === 'dev') {
            $importId = $import ? '('.$import->getId().')' : '';
            $this->logger->debug(sprintf('IMPORT%s: %s', $importId, $msg));
        }
    }

    /**
     * @param $fields
     * @param $data
     *
     * @return bool
     */
    protected function checkForValidValues($fields, &$data)
    {
        $result = false;
        $value  = $data[$fields['name']];
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $result = true;
        } else {
            if (!$this->phoneHelper) {
                $this->phoneHelper = new PhoneNumberHelper();
            }
            try {
                $normalized = $this->phoneHelper->format($value);
                if (!empty($normalized)) {
                    $data[$fields['name']] = $normalized;
                    $result                = true;
                }
            } catch (\Exception $e) {
            }
        }

        return $result;
    }
}
