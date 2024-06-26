<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace ApacheSolrForTypo3\Solr\Task;

use ApacheSolrForTypo3\Solr\Domain\Index\IndexService;
use ApacheSolrForTypo3\Solr\Domain\Site\Site;
use ApacheSolrForTypo3\Solr\System\Environment\CliEnvironment;
use ApacheSolrForTypo3\Solr\System\Environment\WebRootAllReadyDefinedException;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\Exception as DBALException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\ProgressProviderInterface;

/**
 * A worker indexing the items in the index queue. Needs to be set up as one
 * task per root page.
 */
class IndexQueueWorkerTask extends AbstractSolrTask implements ProgressProviderInterface
{
    protected ?int $documentsToIndexLimit = null;

    protected string $forcedWebRoot = '';

    /**
     * Works through the indexing queue and indexes the queued items into Solr and returns TRUE on success,
     * FALSE if no items were indexed or none were found.
     *
     * @throws WebRootAllReadyDefinedException
     * @throws ConnectionException
     * @throws DBALException
     *
     * @noinspection PhpMissingReturnTypeInspection See {@link AbstractTask::execute()}
     */
    public function execute()
    {
        $cliEnvironment = null;

        // Wrapped the CliEnvironment to avoid defining TYPO3_PATH_WEB since this
        // should only be done in the case when running it from outside TYPO3 BE
        // @see #921 and #934 on https://github.com/TYPO3-Solr
        if (Environment::isCli()) {
            $cliEnvironment = GeneralUtility::makeInstance(CliEnvironment::class);
            $cliEnvironment->backup();
            $cliEnvironment->initialize($this->getWebRoot(), Environment::getPublicPath() . '/');
        }

        $site = $this->getSite();
        $indexService = $this->getInitializedIndexService($site);
        $indexService->indexItems($this->documentsToIndexLimit);

        if (Environment::isCli()) {
            $cliEnvironment->restore();
        }

        return true;
    }

    /**
     * In the cli context TYPO3 has chance to determine the webroot.
     * Since we need it for the TSFE related things we allow to set it
     * in the scheduler task and use the ###PATH_typo3### marker in the
     * setting to be able to define relative paths.
     */
    public function getWebRoot(): string
    {
        if ($this->forcedWebRoot !== '') {
            return $this->replaceWebRootMarkers($this->forcedWebRoot);
        }

        return Environment::getPublicPath() . '/';
    }

    /**
     * Replaces the markers containing in $webRoot string
     */
    protected function replaceWebRootMarkers(string $webRoot): string
    {
        if (str_contains($webRoot, '###PATH_typo3###')) {
            $webRoot = str_replace('###PATH_typo3###', Environment::getPublicPath() . '/typo3/', $webRoot);
        }

        if (str_contains($webRoot, '###PATH_site###')) {
            $webRoot = str_replace('###PATH_site###', Environment::getPublicPath() . '/', $webRoot);
        }

        return $webRoot;
    }

    /**
     * Returns some additional information about indexing progress, shown in
     * the scheduler's task overview list.
     *
     * @throws DBALException
     *
     * @noinspection PhpMissingReturnTypeInspection {@link AbstractTask::getAdditionalInformation()}
     */
    public function getAdditionalInformation()
    {
        $site = $this->getSite();

        if (is_null($site)) {
            return 'Invalid site configuration for scheduler please re-create the task!';
        }

        $message = 'Site: ' . $site->getLabel();

        $indexService = $this->getInitializedIndexService($site);
        $failedItemsCount = $indexService->getFailCount();

        if ($failedItemsCount) {
            $message .= ' Failures: ' . $failedItemsCount;
        }

        $message .= ' / Using webroot: ' . htmlspecialchars($this->getWebRoot());

        return $message;
    }

    /**
     * Gets the indexing progress as a two decimal precision float. f.e. 44.87
     *
     * @throws DBALException
     *
     * @noinspection PhpMissingReturnTypeInspection {@link ProgressProviderInterface::getProgress}
     */
    public function getProgress()
    {
        $site = $this->getSite();
        if (is_null($site)) {
            return 0.0;
        }

        $indexService = $this->getInitializedIndexService($site);
        return $indexService->getProgress();
    }

    public function getDocumentsToIndexLimit(): ?int
    {
        return $this->documentsToIndexLimit;
    }

    public function setDocumentsToIndexLimit(int|string $limit): void
    {
        $this->documentsToIndexLimit = (int)$limit;
    }

    public function setForcedWebRoot(string $forcedWebRoot): void
    {
        $this->forcedWebRoot = $forcedWebRoot;
    }

    public function getForcedWebRoot(): string
    {
        return $this->forcedWebRoot;
    }

    /**
     * Returns the initialized IndexService instance.
     */
    protected function getInitializedIndexService(Site $site): IndexService
    {
        $indexService = GeneralUtility::makeInstance(IndexService::class, $site);
        $indexService->setContextTask($this);
        return $indexService;
    }
}
