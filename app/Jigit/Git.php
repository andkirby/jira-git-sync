<?php
/**
 * Created by PhpStorm.
 * User: kirby
 * Date: 8/15/2014
 * Time: 3:13 AM
 */

namespace Jigit;
use Jigit\Config;
use Jigit\Dispatcher\InterfaceDispatcher;
use Jigit\Vcs\InterfaceVcs;
use Lib\Exception;

/**
 * GIT adapter
 *
 * @package Jigit
 */
class Git implements InterfaceVcs
{
    /**#@+
     * Log delimiters
     */
    const LOG_PARAM_DELIMITER = '|@|';
    const LOG_DELIMITER = '|@||';
    /**#@-*/

    /**
     * Dispatcher
     *
     * @var InterfaceDispatcher
     */
    protected $_dispatcher;

    /**
     * Requested commits list
     *
     * @var array
     */
    protected $_commits;

    /**
     * Status of ignoring wrong commits
     *
     * @var bool
     */
    protected $_checkWrongCommits = false;

    /**
     * Get status of checking wrong commits
     *
     * @return boolean
     */
    public function isCheckWrongCommits()
    {
        return $this->_checkWrongCommits;
    }

    /**
     * Set check wrong commits status
     *
     * @param bool $ignoreWrongCommits
     * @return $this
     */
    public function setCheckNotValidCommits($ignoreWrongCommits)
    {
        $this->_checkWrongCommits = $ignoreWrongCommits;
        return $this;
    }

    /**
     * Get JIRA keys from range
     *
     * @return array
     * @throws UserException
     */
    public function getCommits()
    {
        if (null === $this->_commits) {
            /**
             * Get issues between different code versions
             */
            $branchLow = Config\Project::getGitBranchLow();
            $branchTop = Config\Project::getGitBranchTop();

            $this->aggregateCommits($branchLow, $branchTop);
        }
        return $this->_commits;
    }

    /**
     * Run GIT command in project dir
     *
     * @param string        $command
     * @param null|string   $gitRoot
     * @return mixed
     */
    public static function runInProjectDir($command, $gitRoot = null)
    {
        if (false === strpos($command, 'git ')) {
            $command = 'git ' . $command;
        }
        $gitRoot = $gitRoot ?: Config\Project::getProjectRoot();
        $command = str_replace('git ', "git --git-dir $gitRoot/.git/ ", $command);
        return self::run($command);
    }

    /**
     * Get tags list
     *
     * @param bool $reverse
     * @return array
     */
    public function getTags($reverse = true)
    {
        $tags = explode("\n", trim($this->runInProjectDir('tag -l')));
        return $this->_sortTags($reverse, $tags);
    }

    /**
     * Get branches list
     *
     * @param bool $withRemote
     * @return array
     */
    public function getBranches($withRemote = true)
    {
        if ($withRemote) {
            $result   = trim($this->runInProjectDir('branch -a'));
            $result   = str_replace('remotes/', '', $result);
        } else {
            $result   = trim($this->runInProjectDir('branch'));
        }
        return explode("\n", $result);
    }

    /**
     * Sort tags
     *
     * @param bool $reverse
     * @param array $tags
     * @return array
     */
    protected function _sortTags($reverse, $tags)
    {
        //@startSkipCommitHooks
        $callFunction = function ($a, $b) {
            return -1 * version_compare($a, $b);
        };
        //@finishSkipCommitHooks
        usort($tags, $callFunction);
        if ($reverse) {
            $tags = array_reverse($tags);
            return $tags;
        }
        return $tags;
    }

    /**
     * Run GIT command
     *
     * @param string $command
     * @throws Exception
     * @return string
     */
    static public function run($command)
    {
        Config::addDebug('GIT command: ' . PHP_EOL . $command);
        //@startSkipCommitHooks
        $result = `$command 2>&1`; //added trail to capture all output
        //@finishSkipCommitHooks
        if (false !== strpos($result, 'fatal:')
            || false !== strpos($result, 'error:')
        ) {
            throw new Exception('GIT: ' . $result);
        }
        return $result;
    }

    /**
     * Get commits grouped by jira keys.
     *
     * This method will return only commits which passed log contains
     *
     * @param string $log
     * @param string $project
     * @return array
     * @throws UserException
     */
    public function aggregateCommitsByLog($log, $project = null)
    {
        $result = array();
        $this->_commits = $this->_commits ?: array();
        $commits = explode($this->_getCommitDelimiter(), $log);
        foreach ($commits as $commit) {
            $info = $this->_getCommitInfo($commit);
            $issueKey = $this->_getIssueKey($info['message'], $project);
            if ($issueKey) {
                $this->_commits[$issueKey]['hash'][$info['author']][] = $info['hash'];
                $result[$issueKey]['hash'][$info['author']][] = $info['hash'];
            }
        }
        return $result;
    }

    /**
     * Get issue key
     *
     * @param string $message Commit message
     * @param string $project Project key
     * @throws Exception
     * @return mixed
     */
    protected function _getIssueKey($message, $project = null)
    {
        $project = $project ?: Config\Project::getJiraProject();
        $matches = array();
        preg_match('/' . $project . '-[0-9]+/', $message, $matches);
        if (isset($matches[0])) {
            return $matches[0];
        }
        if ($this->isCheckWrongCommits()) {
            throw new Exception("Invalid commit message '$message'.");
        } else {
            return null;
        }
    }

    /**
     * Get commit info
     *
     * @param string $commit
     * @return array
     */
    protected function _getCommitInfo($commit)
    {
        @list($hash, $author, $message) = explode($this->_getCommitParamDelimiter(), trim($commit));
        return array(
            'hash'    => $hash,
            'author'  => $author,
            'message' => $message,
        );
    }

    /**
     * Get commit delimiter
     *
     * @return string
     */
    protected function _getCommitDelimiter()
    {
        return self::LOG_DELIMITER;
    }

    /**
     * Get commit param delimiter
     *
     * @return string
     */
    protected function _getCommitParamDelimiter()
    {
        return self::LOG_PARAM_DELIMITER;
    }

    /**
     * Validate branches
     *
     * @param string $branch
     * @throws Exception
     * @throws UserException
     * @return bool
     */
    public function isBranchValid($branch)
    {
        if (!$branch) {
            throw new Exception('Empty branch name.');
        }
        $branchFound = (bool)$this->runInProjectDir("branch -a --list $branch");
        if (!$branchFound) {
            $branchFound = (bool)$this->runInProjectDir("tag --list $branch");
            if (!$branchFound) {
                throw new UserException("Branch or tag $branch not found.");
            }
        }
        return $branchFound;
    }

    /**
     * Get log format
     *
     * @return string
     */
    public function getLogFormat()
    {
        $delimiter    = $this->_getCommitParamDelimiter();
        $logDelimiter = $this->_getCommitDelimiter();
        return "%h$delimiter%cn$delimiter%s$logDelimiter";
    }

    /**
     * Get log between branches
     *
     * @param string $branchLow
     * @param string $branchTop
     * @param string $format
     * @param string $extraParams
     * @throws Exception
     * @return string
     */
    public function getLog($branchLow, $branchTop, $format, $extraParams = '')
    {
        $this->isBranchValid($branchLow);
        $this->isBranchValid($branchTop);

        if (!$branchLow || !$branchTop) {
            throw new Exception('Branch cannot be empty.');
        }
        //@startSkipCommitHooks
        $log = $this->runInProjectDir(
            "log $branchLow..$branchTop --pretty=format:\"$format\" --no-merges $extraParams"
        );
        //@finishSkipCommitHooks
        return trim($log, $this->_getCommitDelimiter());
    }

    /**
     * Get VCS helper
     *
     * @param string $name
     * @param array  $options
     * @return Git\Helper\AbstractHelper
     */
    public function getHelper($name, array $options = array())
    {
        $class = __NAMESPACE__ . "\\Git\\Helper\\$name";
        /** @var Git\Helper\AbstractHelper $helper */
        $helper = new $class($options);
        $helper->setEngine($this);
        return $helper;
    }

    /**
     * Get Dispatcher
     *
     * @return InterfaceDispatcher
     */
    public function getDispatcher()
    {
        return $this->_dispatcher;
    }

    /**
     * Set Dispatcher
     *
     * @param InterfaceDispatcher $dispatcher
     * @return $this
     */
    public function setDispatcher(InterfaceDispatcher $dispatcher)
    {
        $this->_dispatcher = $dispatcher;
        return $this;
    }

    /**
     * Aggregate commits
     *
     * @param string      $branchLow
     * @param string      $branchTop
     * @param string|null $project
     * @return $this
     * @throws Exception
     * @throws UserException
     */
    public function aggregateCommits($branchLow, $branchTop, $project = null)
    {
        $project = $project ?: Config\Project::getJiraProject();
        $format  = $this->getLogFormat();
        $log     = $this->getLog($branchLow, $branchTop, $format);

        Config::addDebug('LOG: ' . $log);
        if (!$log) {
            throw new UserException('No VCS log found.');
        }
        $this->aggregateCommitsByLog($log, $project);
        return $this;
    }
}
