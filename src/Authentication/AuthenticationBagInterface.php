<?php
declare(strict_types=1);

namespace Gmo\Salesforce\Authentication;


interface AuthenticationBagInterface
{
    public function getToken();

    public function getInstanceUrl();

    public function getTokenType();

    public function issuedAt();

    public function getSignature();

}