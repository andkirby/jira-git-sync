<?php
/**
 * Created by PhpStorm.
 * User: kirby
 * Date: 12.08.2014
 * Time: 1:47
 */
namespace Jigit\Jira;

use Jigit\Config;
use \Jigit\Config\Project as ConfigUser;
use Jigit\Jira\Jql\Filter;

/**
 * Class Jql
 *
 * @package Jigit\Jira
 */
class Jql
{
    /**#@+
     * Query types
     */
    const TYPE_NOT_AFFECTS_CODE                     = 'notAffectsCodeWithFixVersion';
    const TYPE_WITHOUT_FIX_VERSION                  = 'inBranchWithoutFixVersion';
    const TYPE_WITHOUT_AFFECTED_VERSION             = 'inBranchWithoutAffectedVersion';
    const TYPE_OPEN_FOR_IN_PROGRESS_VERSION         = 'inBranchWithoutFixVersionNotDone';
    const TYPE_OPEN_TOP                             = 'openTopForInProgressVersion';
    const TYPE_OPEN_VERSION                         = 'openWithFixVersion';
    const TYPE_PARENT_HAS_COMMIT                    = 'parentIssueHasCommit';
    /**#@-*/

    /**
     * Source file for JQLs list
     *
     * @var string
     */
    protected $_sourceJqlsFile;

    /**
     * Jira settings
     *
     * @var array
     */
    protected $_settings = null;

    /**
     * Set JQLs source file
     *
     * @param string|null $jqlsFile
     */
    public function __construct($jqlsFile = null)
    {
        $this->_sourceJqlsFile = $jqlsFile ?: 'jqls.csv';
    }

    /**
     * Get JQLs
     *
     * @param array $gitKeys
     * @return array
     */
    public function getJqls($gitKeys)
    {
        $jqls = $this->_getDraftJqls();
        $filter = $this->_getFilter();
        $this->_setGitKeys($gitKeys);
        $this->_setJqlSettings();
        $gitKeys    = array('git_keys' => $gitKeys);
        $filterData = array_merge($gitKeys, ConfigUser::getJiraConfig(), $this->_getJqlSetting());
        foreach ($jqls as &$item) {
            $jql = $this->_getDraftJqlString($item);
            $item['jql'] = $filter->filter($jql, $filterData);
        }
        return $jqls;
    }

    /**
     * Get JQL settings
     *
     * @param string $key
     * @return string|null|array
     */
    protected function _getJqlSetting($key = null)
    {
        $csv = new Jql\Reader\Csv();
        if (null === $this->_settings) {
            $this->_settings = $csv->toAssocArray(JIGIT_ROOT . '/jqls-settings.csv');
            $custom          = $csv->toAssocArray(JIGIT_ROOT . '/jqls-settings-local.csv');

            //rewrite settings
            foreach ($this->_getJiraJqlConfig() as $configName => $value) {
                $originalName = str_replace('jira_', '', $configName);
                $custom[$originalName] = $value ?: $custom[$originalName];
            }
            $this->_settings = array_merge_recursive($this->_settings, $custom);
            foreach ($this->_settings as $key => $value) {
                $this->_settings[$key . '_quoted'] = addslashes($value);
            }
        }
        if ($key) {
            return isset($this->_settings[$key]) ? $this->_settings[$key] : null;
        } else {
            return $this->_settings;
        }
    }

    /**
     * Set JQL settings
     *
     * @return $this
     */
    protected function _setJqlSettings()
    {
        $defaultJql = $this->_getJqlSetting('default_jql');
        if ($defaultJql) {
            $defaultJql = ' AND (' . $defaultJql . ')';
            Config::getInstance()->setData('jira_default_jql', $defaultJql);
        }
        Config::getInstance()->setData(
            'jira_not_affects_code_resolutions',
            $this->_getJqlSetting('not_affects_code_resolutions')
        );
        Config::getInstance()->setData(
            'jira_not_affects_code_labels',
            $this->_getJqlSetting('not_affects_code_labels')
        );
        return $this;
    }

    /**
     * Set GIT keys
     *
     * @param array $gitKeys
     * @return $this
     */
    protected function _setGitKeys($gitKeys)
    {
        Config::getInstance()->setData('git_keys', $gitKeys);
        return $this;
    }

    /**
     * Get draft JQLs
     *
     * @return array
     */
    protected function _getDraftJqls()
    {
        $csv  = new Jql\Reader\Csv();
        $jqls = $csv->toArray($this->_getSourceJqlsFile());
        return $jqls;
    }

    /**
     * Get filter
     *
     * @return Filter
     */
    protected function _getFilter()
    {
        return new Filter();
    }

    /**
     * Get draft JQL string
     *
     * @param array $item
     * @return string
     */
    protected function _getDraftJqlString($item)
    {
        return $item['jql'] . ' %jql_default%';
    }

    /**
     * Get source JQLs file
     *
     * @return string
     */
    protected function _getSourceJqlsFile()
    {
        return JIGIT_ROOT . '/' . $this->_sourceJqlsFile;
    }

    /**
     * Get JIRA JQLs config
     *
     * @return array
     */
    protected function _getJiraJqlConfig()
    {
        return ConfigUser::getJiraJqlConfig();
    }
}
