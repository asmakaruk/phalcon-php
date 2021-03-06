<?php
/**
 * Validator Interface
 *
 * @author Andres Gutierrez <andres@phalconphp.com>
 * @author Eduar Carvajal <eduar@phalconphp.com>
 * @version 1.2.6
 * @package Phalcon
*/
namespace Phalcon\Mvc\Model;

/**
 * Phalcon\Mvc\Model\ValidatorInterface initializer
 *
 * @see https://github.com/phalcon/cphalcon/blob/1.2.6/ext/mvc/model/validatorinterface.c
 */
interface ValidatorInterface
{
    /**
     * Returns messages generated by the validator
     *
     * @return array
     */
    public function getMessages();

    /**
     * Executes the validator
     *
     * @param \Phalcon\Mvc\ModelInterface $record
     * @return boolean
     */
    public function validate($record);
}
