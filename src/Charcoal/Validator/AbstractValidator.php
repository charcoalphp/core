<?php

namespace Charcoal\Validator;

use \Charcoal\Validator\ValidatabaleInterface as ValidatabaleInterface;
use \Charcoal\Validator\ValidatorInterface as ValidatorInterface;
use \Charcoal\Validator\ValidatableInterface as ValidatableInterface;
use \Charcoal\Validator\ValidatorResult as ValidatorResult;

/**
* An abstract class that implements most of ValidatorInterface.
*
* The only abstract method in the class is `validate()`
*/
abstract class AbstractValidator implements ValidatorInterface
{
    const ERROR     = 'error';
    const WARNING   = 'warning';
    const NOTICE    = 'notice';

    /**
    * @var ValidatableInterface
    */
    protected $_model;

    /**
    * @var array $_results array of ValidatorResult
    */
    private $_results = [];

    public function __construct(ValidatableInterface $model)
    {
        $this->_model = $model;
    }

    public function error($msg, $ident = null)
    {
        return $this->log(self::ERROR, $msg, $ident);
    }

    public function warning($msg, $ident = null)
    {
        return $this->log(self::WARNING, $msg, $ident);
    }

    public function notice($msg, $ident = null)
    {
        return $this->log(self::NOTICE, $msg, $ident);
    }

    public function log($level, $msg, $ident = null)
    {
        $this->add_result([
            'ident'=>(($ident !== null) ? $ident : ''),
            'level'=>$level,
            'message'=>$msg
        ]);
        return $this;
    }

    /**
    * @param array|ValidatorResult $result
    * @throws \InvalidArgumentException if result is not an array or object
    * @return AbstractValidator Chainable
    */
    public function add_result($result)
    {
        if (is_array($result)) {
            $result = new ValidatorResult($result);
        } else if (!($result instanceof ValidatorResult)) {
            throw new \InvalidArgumentException('ValidatorResult must be an array or a ValidatorResult object');
        }
        $level = $result->level();
        if (!isset($this->_results[$level])) {
            $this->_results[$level] = [];
        }
        $this->_results[$level][] = $result;
        return $this;
    }

    /**
    * @return array
    */
    public function results()
    {
        return $this->_results;
    }

    /**
    * @return array
    */
    public function error_results()
    {
        if (!isset($this->_results[self::ERROR])) {
            return [];
        }
        return $this->_results[self::ERROR];
    }

    /**
    * @return array
    */
    public function warning_results()
    {
        if (!isset($this->_results[self::WARNING])) {
            return [];
        }
        return $this->_results[self::WARNING];
    }

    /**
    * @return array
    */
    public function notice_results()
    {
        if (!isset($this->_results[self::NOTICE])) {
            return [];
        }
        return $this->_results[self::NOTICE];
    }

    public function merge(ValidatorInterface $v, $ident_prefix = null)
    {
        $results = $v->results();
        foreach ($results as $level => $level_results) {
            foreach ($level_results as $r) {
                if ($ident_prefix !== null) {
                    $r->set_ident($ident_prefix.'.'.$r->ident());
                }
                $this->add_result($r);
            }
        }
        return $this;
    }

    abstract public function validate();
}
