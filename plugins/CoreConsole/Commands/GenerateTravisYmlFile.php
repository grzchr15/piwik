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
 * TODO: refactor into multiple classes if possible
 *
 * verification commands:
 * Y ./console generate:travis-yml --core [ with existing core .travis.yml ]
 * Y ./console generate:travis-yml --plugin=UrlShortener [ without existing, check no tests ]
 * Y ./console generate:travis-yml --plugin=MetaSites [ with existing ]
 * Y ./console generate:travis-yml --plugin=MetaSites [ without existing ]
 * Y ./console generate:travis-yml --plugin=MetaSites --artifacts-pass=... --github-token=... [ without & with ]
 * Y ./console generate:travis-yml --core --artifacts-pass=... --github-token=... [ with existing, should not modify ]
 */
class GenerateTravisYmlFile extends ConsoleCommand
{
    /**
     * TODO
     */
    private static $travisYmlSectionNames = array(
        'php',
        'language',
        'script',
        'before_install',
        'install',
        'before_script',
        'after_script',
        'after_success'
    );

    /**
     * TODO
     */
    private $targetPlugin;

    /**
     * TODO
     */
    private $outputYmlPath;

    /**
     * TODO
     */
    protected function configure()
    {
        $this->setName('generate:travis-yml')
             ->setDescription('Generates a travis.yml file for this plugin.')
             ->addOption('plugin', null, InputOption::VALUE_REQUIRED, 'The plugin for whom a .travis.yml file should be generated.')
             ->addOption('core', null, InputOption::VALUE_NONE, 'Supplied when generating the .travis.yml file for Piwik core.'
                                                          . ' Should only be used by core developers.')
             ->addOption('artifacts-pass', null, InputOption::VALUE_REQUIRED,
                "Password to the Piwik build artifacts server. Will be encrypted in the .travis.yml file.")
             ->addOption('github-token', null, InputOption::VALUE_REQUIRED,
                "Github token of a user w/ push access to this repository. Used to auto-commit updates to the "
              . ".travis.yml file and checkout dependencies. Will be encrypted in the .travis.yml file.")
             ->addOption('dump', null, InputOption::VALUE_REQUIRED, "Debugging option. Saves the output .travis.yml to the specified file.");
    }

    /**
     * TODO
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->targetPlugin = $input->getOption('plugin');

        $this->outputYmlPath = $this->getTravisYmlOutputPath($input);

        $view = new View("@CoreConsole/travis.yml");
        $this->configureTravisYmlView($view, $input, $output);
        $travisYmlContents = $view->render();

        $writePath = $input->getOption('dump');
        if (empty($writePath)) {
            $writePath = $this->outputYmlPath;
        }

        file_put_contents($writePath, $travisYmlContents);

        $this->writeSuccessMessage($output, array("Generated .travis.yml file at '{$this->outputYmlPath}'!"));
    }

    private function configureTravisYmlView(View $view, InputInterface $input, OutputInterface $output)
    {
        $view->pluginName = $this->targetPlugin;
        $view->sections = $this->getTravisYmlSections();

        $this->processExistingTravisYml($view);

        if (!empty($view->existingEnv)) {
            $view->globalVars = $this->getGlobalVariables($input);
        } else {
            $output->writeln("<info>Existing .yml files found, ignoring global variables specified on command line.</info>");
        }

        list($view->testsToRun, $view->testsToExclude) = $this->getTestsToRun($input);
    }

    private function getTravisYmlSections()
    {
        $result = array();
        foreach (self::$travisYmlSectionNames as $name) {
            $sectionFilePath = PIWIK_INCLUDE_PATH . '/tests/travis/' . $name . '.yml';
            if (is_readable($sectionFilePath)) {
                $result[$name] = file_get_contents($sectionFilePath);
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
                              . " Execute './console help generate:travis-yml' for more info");
        }
    }

    private function getGlobalVariables(InputInterface $input)
    {
        $globalVars = array();

        $artifactsPass = $input->getOption('artifacts-pass');
        if (!empty($artifactsPass)) {
            $globalVars[] = array('secure' => true,
                                  'value' => $this->travisEncrypt("ARTIFACTS_PASS=" . $artifactsPass));
        }

        $githubToken = $input->getOption('github-token');
        if (!empty($githubToken)) {
            $globalVars[] = array('secure' => true,
                                  'value' => $this->travisEncrypt("GITHUB_USER_TOKEN=" . $githubToken));
        }

        return $globalVars;
    }

    private function getTestsToRun(InputInterface $input)
    {
        $testsToRun = array();
        $testsToExclude = array();

        if ($this->isTargetPluginContainsPluginTests()) {
            $testsToRun[] = array('name' => 'PluginTests',
                                  'vars' => "MYSQL_ADAPTER=PDO_MYSQL");
            $testsToRun[] = array('name' => 'PluginTests',
                                  'vars' => "MYSQL_ADAPTER=PDO_MYSQL TEST_AGAINST_CORE=latest_stable");

            $testsToExclude[] = array('description' => 'execute latest stable tests only w/ PHP 5.5',
                                      'php' => '5.3',
                                      'env' => 'PluginTests MYSQL_ADAPTER=PDO_MYSQL TEST_AGAINST_CORE=latest_stable');
            $testsToExclude[] = array('php' => '5.4',
                                      'env' => 'PluginTests MYSQL_ADAPTER=PDO_MYSQL TEST_AGAINST_CORE=latest_stable');
        }
        if ($this->isTargetPluginContainsUITests()) {
            $testsToRun[] = array('name' => 'UITests',
                                  'vars' => "MYSQL_ADAPTER=PDO_MYSQL");

            $testsToExclude[] = array('description' => 'execute UI tests only w/ PHP 5.5',
                                      'php' => '5.3',
                                      'env' => 'TEST_SUITE=UITests MYSQL_ADAPTER=PDO_MYSQL');
            $testsToExclude[] = array('php' => '5.4',
                                      'env' => 'TEST_SUITE=UITests MYSQL_ADAPTER=PDO_MYSQL');
        }

        return array($testsToRun, $testsToExclude);
    }

    /**
     * TODO
     */
    private function isTargetPluginContainsPluginTests()
    {
        $pluginPath = $this->getPluginRootFolder();
        return $this->doesFolderContainPluginTests($pluginPath . "/tests")
            || $this->doesFolderContainPluginTests($pluginPath . "/Test");
    }

    /**
     * TODO
     */
    private function doesFolderContainPluginTests($folderPath)
    {
        $testFiles = glob($folderPath . "/**/*Test.php");
        return !empty($testFIles);
    }

    /**
     * TODO
     */
    private function isTargetPluginContainsUITests()
    {
        $pluginPath = $this->getPluginRootFolder();
        return $this->doesFolderContainUITests($pluginPath . "/tests")
            || $this->doesFolderContainUITests($pluginPath . "/Test");
    }

    private function doesFolderContainUITests($folderPath)
    {
        $testFiles = glob($folderPath . "/**/*_spec.js");
        return !empty($testFiles);
    }

    private function travisEncrypt($data)
    {
        echo "Encrypting \"$data\"...\n";

        $command = "travis encrypt \"$data\"";

        // change dir to target plugin since plugin will be in its own git repo
        if (!empty($this->targetPlugin)) {
            $command = "cd \"" . $this->getPluginRootFolder() . "\" && " . $command;
        }

        exec($command, $output, $returnCode);
        if ($returnCode !== 0) {
            throw new Exception("Cannot encrypt \"$data\" for travis! Please make sure you have the travis command line "
                              . "utility installed (see http://blog.travis-ci.com/2013-01-14-new-client/).\n\n"
                              . "return code: $returnCode\n\n"
                              . "travis output:\n\n" . implode("\n", $output));
        }

        if (empty($output)) {
            throw new Exception("Cannot parse travis encrypt output:\n\n" . implode("\n", $output));
        }

        // when not executed from a command line travis encrypt will return only the encrypted data
        $encryptedData = $output[0];
        if (substr($encryptedData, 0, 1) == '"') {
            $encryptedData = substr($encryptedData, 1);
        }
        if (substr($encryptedData, -1) == '"') {
            $encryptedData = substr($encryptedData, 0, strlen($encryptedData) - 1);
        }

        return $encryptedData;
    }

    private function processExistingTravisYml(View $view)
    {
        if (!file_exists($this->outputYmlPath)) {
            return;
        }

        $existingYamlText = file_get_contents($this->outputYmlPath);
        foreach ($this->getRootSectionsFromYaml($existingYamlText) as $sectionName => $offset) {
            $section = $this->getRootSectionText($existingYamlText, $sectionName, $offset);
            if ($sectionName == 'env') {
                $view->existingEnv = $section;
            } else if ($sectionName == 'matrix') {
                $view->existingMatrix = $section;
            } else if (!in_array($sectionName, self::$travisYmlSectionNames)) {
                $view->extraSections .= "\n\n$sectionName:" . $section;
            }
        }
    }

    private function getRootSectionsFromYaml($yamlText)
    {
        preg_match_all("/^[a-zA-Z_]+:/m", $yamlText, $allMatches, PREG_OFFSET_CAPTURE);

        $result = array();
        foreach ($allMatches[0] as $match) {
            $matchLength = strlen($match[0]);
            $sectionName = substr($match[0], 0, $matchLength - 1);

            $result[$sectionName] = $match[1] + $matchLength;
        }
        return $result;
    }

    private function getRootSectionText($yamlText, $sectionName, $offset)
    {
        preg_match("/^[^\s]/m", $yamlText, $endMatches, PREG_OFFSET_CAPTURE, $offset);

        $endPos = isset($endMatches[0][1]) ? $endMatches[0][1] : strlen($yamlText);

        return substr($yamlText, $offset, $endPos - $offset);
    }

    private function getPluginRootFolder()
    {
        return PIWIK_INCLUDE_PATH . "/plugins/{$this->targetPlugin}";
    }
}