<?php
namespace Jigit;
use Lib\Controller;

/**
 * Class IndexController
 */
class IndexController extends Controller\AbstractController
{
    /**
     * Runner
     *
     * @var Run
     */
    protected $_runner;

    /**
     * Initialize
     */
    public function init()
    {
        //TODO Refactor this set
        !defined('JIGIT_ROOT') && define('JIGIT_ROOT', realpath(APP_ROOT . '/..'));

        \Zend_Registry::set('runner', $this->_getRunner());
    }

    /**
     * Index action
     */
    public function indexAction()
    {
        try {
            $this->_getRunner()->initConfig();
            $this->_checkInstallation();
        } catch (UserException $e) {
            $this->_getSession()->addError($e->getMessage());
        } catch (\Exception $e) {
            $this->_getSession()->addError('An error occurred on request.');
        }

        $this->_loadLayout();
        $this->_renderBlocks();
    }

    /**
     * Post action
     */
    public function postAction()
    {
        try {
            $report = new Report();
            \Zend_Registry::set('report', $report);
            $this->_setProjectAlias();
            $this->_getRunner()
                ->initialize($this->getRequest()->getPostParam('action'), $this->getRequest()->getPost());
            $this->_getRunner()
                ->getVcs()->validate();
            $this->_processAction($report);
        } catch (UserException $e) {
            $this->_getSession()->addError($e->getMessage());
        } catch (\Exception $e) {
            $this->_getSession()->addError('An error occurred on request.');
        }
        $this->_loadLayout();
        $this->_renderBlocks();
    }

    /**
     * Process action
     *
     * @param Report $report
     * @throws Exception
     * @return Report
     */
    protected function _processAction($report)
    {
        return $this->_getRunner()->processAction($report);
    }

    /**
     * Get runner
     *
     * @return Run
     */
    protected function _getRunner()
    {
        if (null === $this->_runner) {
            $this->_runner = new Run();
        }
        return $this->_runner;
    }

    /**
     * Set project alias
     *
     * @return $this
     */
    protected function _setProjectAlias()
    {
        $this->getRequest()->setPostParam('p', $this->getRequest()->getPostParam('project'));
        return $this;
    }

    /**
     * Check installation
     *
     * @throws UserException
     * @return bool
     */
    protected function _checkInstallation()
    {
        $helper = new Helper\Base();
        if (!$helper->isInstalled()) {
            throw new UserException('JiGIT was not fully installed. Please check your configuration.');
        }
        return true;
    }
}
