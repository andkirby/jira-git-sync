<?php
/**
 * Created by PhpStorm.
 * User: kirby
 * Date: 12.08.2014
 * Time: 2:01
 */

namespace Jigit\Jira;

use Jigit\UserException as UserException;
use Jigit\Exception as Exception;

/**
 * Class Password
 *
 * @package Jigit\Jira
 */
class Password
{
    /**
     * System ID
     *
     * @var string
     */
    static protected $_uuid;

    /**
     * Password info
     *
     * @var string
     */
    protected $_passwordInfo;

    /**
     * Initialize
     *
     * @throws \Jigit\UserException
     */
    protected function _init()
    {
        $this->_checkPasswordSet();
        if ($this->_isPasswordInfoEncrypted()) {
            $this->_encryptPassword($this->_getPasswordInfo());
        }
    }

    /**
     * Get password
     *
     * @return string
     * @throws UserException
     */
    public function getPassword()
    {
        $this->_init();
        list($passId, $jiraPasswordHash) = $this->_extractPasswordInfo();
        $this->_checkSystemId($passId);
        return $this->_decryptPassword($jiraPasswordHash);
    }

    /**
     * Get system UUID
     *
     * @return string
     */
    protected function _getUuid()
    {
        if (null === self::$_uuid) {
            if ($this->_isWindows()) {
                self::$_uuid = $this->_getWindowsUuid();
            } else {
                self::$_uuid = $this->_getUnixUuid();
                if (null === self::$_uuid) {
                    self::$_uuid = $this->_getDefaultUuid();
                }
            }
            self::$_uuid = base64_encode(self::$_uuid);
        }
        return self::$_uuid;
    }

    /**
     * @return bool
     */
    protected function _isWindows()
    {
        return isset($_SERVER['WINDIR']) && $_SERVER['WINDIR'];
    }

    /**
     * Get Windows UUID
     *
     * @return mixed
     */
    protected function _getWindowsUuid()
    {
        return `wmic csproduct get uuid`;
    }

    /**
     * Get Unix UUID
     *
     * @return null
     */
    protected function _getUnixUuid()
    {
        $unixCommands = array(
            'hostid',
            'sysctl kern.hostuuid',
            'blkid',
        );
        $uuid = null;
        foreach ($unixCommands as $command) {
            $result = `$command`;
            if (false === strpos($uuid, 'Permission')
                && false === strpos($uuid, 'not found')
            ) {
                $uuid = $result;
                break;
            }
        }
        return $uuid;
    }

    /**
     * Get default UUID
     *
     * @return string
     */
    protected function _getDefaultUuid()
    {
        return phpversion();
    }

    /**
     * Check password is encrypted or not
     *
     * Let's agree that password cannot be more than 30 symbols
     *
     * @return bool
     */
    protected function _isPasswordInfoEncrypted()
    {
        return mb_strlen($this->_getPasswordInfo()) < 30;
    }

    /**
     * Extract password info
     *
     * @return array
     */
    protected function _extractPasswordInfo()
    {
        @list($passId, $jiraPasswordHash) = explode(' ', $this->_getPasswordInfo());
        return array($passId, $jiraPasswordHash);
    }

    /**
     * Check system ID matched
     *
     * @param string $passId
     * @return $this
     * @throws UserException
     */
    protected function _checkSystemId($passId)
    {
        if ($passId != md5($this->_getUuid())) {
            throw new UserException(
                'Your system ID is not matched. Please set your JIRA password in the file "jira.password".'
            );
        }
        return $this;
    }

    /**
     * Get password info file
     *
     * @return string
     */
    protected function _getPasswordFile()
    {
        return JIGIT_ROOT . '/jira.password';
    }

    /**
     * Get password info
     *
     * @return string
     */
    protected function _getPasswordInfo()
    {
        if (null === $this->_passwordInfo) {
            $this->_passwordInfo = trim(@file_get_contents($this->_getPasswordFile()));
        }
        return $this->_passwordInfo;
    }

    /**
     * Reset password info
     *
     * Set info to file and property
     *
     * @param string $jiraPasswordInfo
     * @return int
     */
    protected function _resetPasswordInfo($jiraPasswordInfo)
    {
        $this->_passwordInfo = trim($jiraPasswordInfo);
        return $this->_writePasswordInfo($this->_passwordInfo);
    }

    /**
     * Write password info to file
     *
     * @param string $jiraPasswordInfo
     * @return int
     */
    protected function _writePasswordInfo($jiraPasswordInfo)
    {
        return file_put_contents($this->_getPasswordFile(), trim($jiraPasswordInfo));
    }

    /**
     * Decrypt password
     *
     * @param string $passwordHash
     * @return mixed
     */
    protected function _decryptPassword($passwordHash)
    {
        return $this->_decrypt($passwordHash, $this->_getUuid());
    }

    /**
     * @param $password
     * @return array
     */
    protected function _encryptPassword($password)
    {
        $key = $this->_getUuid();
        $jiraPasswordHash = $this->_encrypt($password, $key);
        $passId = md5($key);
        $password = "$passId $jiraPasswordHash";
        $this->_resetPasswordInfo($password);
        return $this;
    }

    /**
     * Encrypt string
     *
     * @param string $string
     * @param string $key
     * @return string
     */
    protected function _encrypt($string, $key)
    {
        $hash = base64_encode($key . trim($string));
        //encrypt hash
        for ($i = 0; $i <= 20; $i++) {
            $hash = str_replace($key[$i], "|$i|", $hash);
        }
        return $hash;
    }

    /**
     * Decrypt string
     *
     * @param string $string
     * @param string $key
     * @return string
     */
    protected function _decrypt($string, $key)
    {
        for ($i = 20; $i >= 0; $i--) {
            $string = str_replace(
                "|$i|", $key[$i], $string
            );
        }
        return str_replace($key, '', base64_decode($string));
    }

    /**
     * Check password set
     *
     * @return $this
     * @throws \Jigit\UserException
     */
    protected function _checkPasswordSet()
    {
        if (!$this->_getPasswordInfo()) {
            throw new UserException(
                'Please use your JIRA password in the file "jira.password".'
            );
        }
        return $this;
    }
}
