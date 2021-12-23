<?php
namespace Erk\Flow\BootstrapForm\Persistence;

/*
 * This file is part of the Erk.Flow.BootstrapForm package.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Form\Exception\PersistenceManagerException;
use Neos\Form\Persistence\FormPersistenceManagerInterface;
use Neos\Utility\Files;
use Symfony\Component\Yaml\Yaml;

/**
 * persistence identifier is some resource:// uri probably
 *
 * @Flow\Scope("singleton")
 */
class YamlPersistenceManager implements FormPersistenceManagerInterface
{

    /**
     * @var array
     */
    protected array $savePaths = [];

    /**
     * @var array
     */
    protected array $disabledForms = [];

    /**
     * @Flow\InjectConfiguration(path="yamlPersistenceManager.savePath", package="Neos.Form")
     * @var string
     */
    protected string $fallbackSavePath;

    /**
     *
     * @Flow\InjectConfiguration(package="Erk.Flow.BootstrapForm")
     * @var array
     */
    protected array $formSettings;

    /**
     * @Flow\InjectConfiguration
     * @var array
     */
    protected array $settings;

    /**
     * @return void
     * @throws \Neos\Utility\Exception\FilesException
     * @throws PersistenceManagerException
     */
    public function initializeObject(): void
    {
        $settings = $this->settings;

        if (isset($this->formSettings['yamlPersistenceManager'])) {
            $settings = $this->formSettings;
        }

        if (isset($settings['yamlPersistenceManager']['savePaths'])) {
            $this->savePaths = $settings['yamlPersistenceManager']['savePaths'];

            if ($this->savePaths !== []) {
                foreach ($this->savePaths as $savePath => $useSavePath) {
                    if ($useSavePath && !is_dir($savePath)) {
                        Files::createDirectoryRecursively($savePath);
                    }
                }
            }
            else {
                throw new PersistenceManagerException('Please add a savePath', 34234525235);
            }
        }

        // Remove disabled forms
        if (isset($settings['yamlPersistenceManager']['disabledForms']) && is_array($settings['yamlPersistenceManager']['disabledForms'])) {
            $this->disabledForms = $settings['yamlPersistenceManager']['disabledForms'];
        }

        if (empty($this->savePaths) && !empty($this->fallbackSavePath)) {
            if (!is_dir($this->fallbackSavePath)) {
                Files::createDirectoryRecursively($this->fallbackSavePath);
            }

            $this->savePaths = [$this->fallbackSavePath => true];
        }
    }

    /**
     * Load the array form representation identified by $persistenceIdentifier, and return it
     *
     * @param string $persistenceIdentifier
     * @return array
     * @throws PersistenceManagerException
     */
    public function load($persistenceIdentifier): array
    {
        if (!$this->exists($persistenceIdentifier)) {
            throw new PersistenceManagerException(sprintf('The form with the identifier "%s" could not loaded or does not exists in "%s".', $persistenceIdentifier, $this->getFormPathAndFilename($persistenceIdentifier)));
        }

        $formPathAndFilename = $this->getFormPathAndFilename($persistenceIdentifier);
        return Yaml::parse(file_get_contents($formPathAndFilename));
    }

    /**
     * Save the array form representation identified by $persistenceIdentifier
     *
     * @param string $persistenceIdentifier
     * @param array $formDefinition
     * @return void
     * @throws PersistenceManagerException
     */
    public function save($persistenceIdentifier, array $formDefinition): void
    {
        $formPathAndFileName = $this->getFormPathAndFilename($persistenceIdentifier);
        file_put_contents($formPathAndFileName, Yaml::dump($formDefinition, 99));
    }

    /**
     * Check whether a form with the specified $persistenceIdentifier exists
     *
     * @param string $persistenceIdentifier
     * @return bool TRUE if a form with the given $persistenceIdentifier can be loaded, otherwise FALSE
     * @throws PersistenceManagerException
     */
    public function exists($persistenceIdentifier): bool
    {
        return is_file($this->getFormPathAndFilename($persistenceIdentifier));
    }

    /**
     * List all form definitions which can be loaded through this form persistence
     * manager.
     *
     * Returns an associative array with each item containing the keys 'name' (the human-readable name of the form)
     * and 'persistenceIdentifier' (the unique identifier for the Form Persistence Manager e.g. the path to the saved form definition).
     *
     * @return array in the format array(array('name' => 'Form 01', 'persistenceIdentifier' => 'path1'), array( .... ))
     * @throws PersistenceManagerException
     */
    public function listForms(): array
    {
        $this->assertSavePathIsValid();

        $forms = [];
        foreach ($this->savePaths as $savePath => $useSavePath) {
            if ($useSavePath === false) {
                continue;
            }

            $directoryIterator = new \DirectoryIterator($savePath);

            foreach ($directoryIterator as $fileObject) {
                if (!$fileObject->isFile()) {
                    continue;
                }

                $fileInfo = pathinfo($fileObject->getFilename());
                if (strtolower($fileInfo['extension']) !== 'yaml') {
                    continue;
                }

                $persistenceIdentifier = $fileInfo['filename'];
                $form = $this->load($persistenceIdentifier);

                if (isset($this->disabledForms[$form['identifier']]) && $this->disabledForms[$form['identifier']]) {
                    continue;
                }

                $forms[] = [
                    'identifier' => $form['identifier'],
                    'name' => $form['label'] ?? $form['identifier'],
                    'persistenceIdentifier' => $persistenceIdentifier
                ];
            }
        }

        return $forms;
    }

    /**
     * Returns the absolute path and filename of the form with the specified $persistenceIdentifier
     * Note: This (intentionally) does not check whether the file actually exists
     *
     * @param string $persistenceIdentifier
     * @return string the absolute path and filename of the form with the specified $persistenceIdentifier
     * @throws PersistenceManagerException
     */
    protected function getFormPathAndFilename(string $persistenceIdentifier): string
    {
        $this->assertSavePathIsValid();

        $formFileName = sprintf('%s.yaml', $persistenceIdentifier);
        $firstValidSavePath = '';
        foreach ($this->savePaths as $savePath => $useSavePath) {
            if ($useSavePath) {
                if (empty($firstValidSavePath)) {
                    $firstValidSavePath = $savePath;
                }
                $pathAndFilename = Files::concatenatePaths([$savePath, $formFileName]);
                if (is_file($pathAndFilename)) {
                    return $pathAndFilename;
                }
            }
        }
        return Files::concatenatePaths(array($firstValidSavePath, $formFileName));
    }

    /**
     * Check if the save paths is set and points to a directory.
     *
     * @return void
     * @throws PersistenceManagerException
     */
    protected function assertSavePathIsValid(): void
    {
        if ($this->savePaths === []) {
            throw new PersistenceManagerException('The savePaths is not usable.', 1532344964);
        } else {
            foreach ($this->savePaths as $savePath => $useSavePath) {
                if ($useSavePath && !is_dir($savePath)) {
                    throw new PersistenceManagerException(sprintf('The savePath "%s" is not usable.', $savePath), 1532345130);
                }
            }
        }
    }
}
