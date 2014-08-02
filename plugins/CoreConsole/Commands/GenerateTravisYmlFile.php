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
use Symfony\Component\Yaml\Yaml; // symfony-yaml included by composer for dev install
use Exception;

/**
 * TODO
 *
 * TODO: refactor into multiple classes if possible
 * TODO: make sure any custom commands in .yml are preserved
 *
 * verification commands:
 * - ./console generate:travis-yml --core [ with existing core .travis.yml ]
 * - ./console generate:travis-yml --plugin=UrlShortener [ without existing, check no tests ]
 * - ./console generate:travis-yml --plugin=MetaSites [ with existing ]
 * - ./console generate:travis-yml --plugin=MetaSites [ without existing ]
 * - ./console generate:travis-yml --plugin=MetaSites --artifacts-pass=... --github-token=... [ without & with ]
 * - ./console generate:travis-yml --core --artifacts-pass=... --github-token=... [ with existing, should not modify ]
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
             ->addArgument('plugin', InputArgument::OPTIONAL, 'The plugin for whom a .travis.yml file should be generated.')
             ->addOption('core', null, InputOption::VALUE_NONE, 'Supplied when generating the .travis.yml file for Piwik core.'
                                                          . ' Should only be used by core developers.')
             ->addOption('artifacts-pass', null, InputOption::VALUE_REQUIRED,
                "Password to the Piwik build artifacts server. Will be encrypted in the .travis.yml file.")
             ->addOption('github-token', null, InputOption::VALUE_REQUIRED,
                "Github token of a user w/ push access to this repository. Used to auto-commit updates to the "
              . ".travis.yml file and checkout dependencies. Will be encrypted in the .travis.yml file.");
    }

    /**
     * TODO
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->targetPlugin = $input->getArgument('plugin');

        $this->outputYmlPath = $this->getTravisYmlOutputPath($input);

        $view = new View("@CoreConsole/.travis.yml"); // TODO: template file shouldn't be hidden...
        $this->configureTravisYmlView($view, $input);
        $travisYmlContents = $view->render();

        file_put_contents($this->outputYmlPath, $travisYmlContents);

        $this->writeSuccessMessage($output, array("Generated .travis.yml file at '{$this->outputYmlPath}'!"));
    }

    private function configureTravisYmlView(View $view, InputInterface $input)
    {
        $view->pluginName = $this->targetPlugin;
        $view->sections = $this->getTravisYmlSections();

        $view->globalVars = $this->getGlobalVariables($input);
        list($view->testsToRun, $view->testsToExclude) = $this->getTestsToRun($input);

        $this->processExistingTravisYml($view);
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
            $globalVars = array('name' => 'ARTIFACTS_PASS',
                                'value' => $this->travisEncrypt("ARTIFACTS_PASS=" . $artifactsPass));
        }

        $githubToken = $input->getOption('github-token');
        if (!empty($githubToken)) {
            $globalVars = array('name' => 'GITHUB_USER_TOKEN',
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

            $testsToExclude = array('description' => 'execute UI tests only w/ PHP 5.5',
                                    'php' => '5.3',
                                    'env' => 'TEST_SUITE=UITests MYSQL_ADAPTER=PDO_MYSQL');
            $testsToExclude = array('php' => '5.4',
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
        $command = "travis encrypt \"$data\"";

        // change dir to target plugin since plugin will be in its own git repo
        if (!empty($this->targetPlugin)) {
            $command = "cd \"" . $this->getPluginRootFolder() . "\" && " . $command;
        }

        $returnCode = exec($command, $output);
        if ($returnCode !== 0) {
            throw new Exception("Cannot encrypt \"$data\" for travis! Please make sure you have the travis command line "
                              . "utility installed (see http://blog.travis-ci.com/2013-01-14-new-client/).\n\n"
                              . "travis output:\n\n" . implode("\n", $output));
        }

        // find output line w/ the 'secure: ' encrypted entry
        foreach ($output as $line) {
            $line = trim($line);
            if (strpos($line, "secure:") === 0) {
                return $line;
            }
        }

        // we cannot find the encrypted entry, so fail
        throw new Exception("Cannot parse travis encrypt output:\n\n" . implode("\n", $output));
    }

    private function processExistingTravisYml(View $view)
    {
        if (!file_exists($this->outputYmlPath)) {
            return;
        }

        $existingYaml = file_get_contents($this->outputYmlPath);
        // TODO: explain (hack used to preserve comments)
        $existingYaml = preg_replace('/(\s+)\#/', '\1- #', $existingYaml);

        $existingYaml = Yaml::parse($existingYaml);

        if (!empty($existingYaml['env'])) {
            $view->existingEnv = $this->prettyDumpYaml($existingYaml['env']);
        }
        if (!empty($existingYaml['matrix'])) {
            $view->existingMatrix = $this->prettyDumpYaml($existingYaml['matrix']);
        }
    }

    private function prettyDumpYaml($data, $indent = 0, $firstIndent = true)
    {
        if (!is_array($data)) {
            return Yaml::dump($data);
        } else {
            $tabs = str_repeat("  ", $indent);

            reset($data);
            $firstKey = key($data);

            $result = '';
            foreach ($data as $key => $item) {
                if (is_int($key)) {
                    $yamlKey = '- ';
                    $doFirstIndent = false;
                } else {
                    $yamlKey = $key . ":" . (is_array($item) ? "\n" : " ");
                    $doFirstIndent = true;
                }

                if ($firstIndent
                    || $key != $firstKey
                ) {
                    $result .= $tabs;
                }

                $result .= $yamlKey . rtrim($this->prettyDumpYaml($item, $indent + 1, $doFirstIndent)) . "\n";
            }
            return $result;
        }
    }

    private function getPluginRootFolder()
    {
        return PIWIK_INCLUDE_PATH . "/plugins/{$this->targetPlugin}";
    }
}