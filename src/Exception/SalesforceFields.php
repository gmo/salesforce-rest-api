<?php
namespace Gmo\Salesforce\Exception;

class SalesforceFields extends Salesforce
{
    protected $fields;

    public function __construct($message = "", $code = 0, $fields = array())
    {
        parent::__construct($message, $code);

        $this->fields = is_array($fields) ? $fields : array();
    }

    /**
     * @return string[]
     */
    public function getFields()
    {
        return $this->fields;
    }
}
