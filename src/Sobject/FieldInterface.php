<?php
declare(strict_types=1);

namespace Gmo\Salesforce\Sobject;


interface FieldInterface
{
    public function getName();

    public function getLabel();
}