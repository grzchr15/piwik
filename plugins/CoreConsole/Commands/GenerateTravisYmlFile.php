<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\CoreConsole\Commands;

use Piwik\View;
use Piwik\Plugin\ConsoleCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Exception;

/**
 * TODO
 *
 * TODO: make sure to update existing in-place
 */
class GenerateTravisYmlFile extends ConsoleCommand
{
    /**
     * TODO
     */
    private static $travisYmlSectionNames = array(
        'before_install',
        'install',
        'before_script',
        'after_script'
    );

    /**
     * TODO
     */
    private $targetPlugin;

    /**
     * TODO
     */
    protected function configure()
    {
        $this->setName('generate:travis-yml')
             ->setDescription('Generates a travis.yml file for this plugin.')
             ->addArgument('plugin', InputArgument::OPTIONAL, 'The plugin for whom a .travis.yml file should be generated.')
             ->addOption('core', InputArgument::VALUE_NONE, 'Supplied when generating the .travis.yml file for Piwik core.'
                                                          . ' Should only be used by core developers.');
    }

    /**
     * TODO
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->targetPlugin = $input->getArgument('plugin');

        $travisYmlOutputPath = $this->getTravisYmlOutputPath($input);

        $view = new View($this->getTravisYmlTemplateLocation());
        $this->configureTravisYmlView($view, $input);
        $travisYmlContents = $view->render();

        file_put_contents($travisYmlOutputPath, $travisYmlContents);
    }

    private function configureTravisYmlView(View $view, InputInterface $input)
    {
        $view->pluginName = $this->targetPlugin;
        $view->sections = $this->getTravisYmlSections();

        $view->globalVars = $this->getGlobalVariables($input);
        list($view->testsToRun, $view->testsToExclude) = $this->getTestsToRun($input);
    }

    private function getTravisYmlSections()
    {
        $result = array();
        foreach (self::$travisYmlSectionNames as $name) {
            $sectionFilePath = PIWIK_INCLUDE_PATH . '/tests/travis/' . $name . '.yml';
            if (is_readable($sectionFilePath)) {
                $travisYmlSections[$name] = file_get_contents($sectionFilePath);
            }
        }
        return $result;
    }

    private function getTravisYmlOutputPath(InputInterface $input)
    {
        if ($input->getOption('core')) {
            return PIWIK_INCLUDE_PATH . '/.travis.yml';
        } else if ($this->targetPlugin) {
            $pluginDirectory = PIWIK_INCLUDE_PATH . '/plugins/' . $this->targetPlugin;
            if (!is_writable($pluginDirectory)) {
                throw new Exception("Cannot write to '$pluginDirectory'!");
            }

            return $pluginDirectory . '/.travis.yml';
        } else {
            throw new Exception("Neither plugin argument or --core option specified; don't know where to generate .travis.yml."
                              . "Execute './console help generate:travis-yml' for more info");
        }
    }

    private function getGlobalVariables(InputInterface $input)
    {
        // TODO
    }

    private function getTestsToRun(InputInterface $input)
    {
        $testsToRun = array();
        $testsToExclude = array();

        if ($this->isTargetPluginContainsPluginTests()) { // TODO
            $testMatrix[] = "TEST_SUITE=PluginTests MYSQL_ADAPTER=PDO_MYSQL";
        }
        if ($this->isTargetPluginContainsUITests()) { // TODO
            $testMatrix[] = "TEST_SUITE=UITests MYSQL_ADAPTER=PDO_MYSQL";
        }

        return array($testsToRun, $testsToExclude);
    }

    private function getTravisYmlTemplateLocation()
    {
        return PIWIK_INCLUDE_PATH . '/tests/travis/.travis.yml.template';
    }
}