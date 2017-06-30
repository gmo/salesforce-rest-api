<?php
declare(strict_types=1);

namespace Gmo\Salesforce\Sobject;


class Field implements FieldInterface, \JsonSerializable
{
    /** @var  string */
    protected $name;
    /** @var  string */
    protected $label;

    public function __construct(array $jsonField)
    {
        $this->name = $jsonField['name'];
        $this->label = $jsonField['label'];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function jsonSerialize()
    {
        return [
            'name' => $this->name,
            'label' => $this->label
        ];
    }
}