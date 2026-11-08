<?php

/*******************************************************************************
 *
 *  filename    : Emails/verify/FamilyVerifyEmail.php
 *  last change : 2024-01-31 Philippe Logel
 *  description : Create emails with all the confirmation letters asking member
 *                families to verify the information in the database.
 *
 ******************************************************************************/

namespace EcclesiaCRM\Emails;

use EcclesiaCRM\dto\SystemConfig;

class FamilyVerificationEmail extends BaseEmail
{
    private $token;
    protected $familyName;
    protected $logins;
    protected $password;

    public function __construct($emails, $familyName, $token = "", $logins = [], $password = "")
    {
        $this->familyName = $familyName;
        $this->token = $token;
        $this->logins = $logins;
        $this->password = $password;

        parent::__construct($emails);

        $this->mail->Subject = _("Family"). " : ". $familyName . " (" . gettext("Please verify your family's information").")";
        $this->mail->isHTML(true);
        $this->mail->msgHTML($this->buildMessage());
    }

    public function getTokens()
    {
        $myTokens = ["toName" => $this->familyName . " " . gettext("Family"),
            "verificationToken" => $this->token,
            "body" => SystemConfig::getValue("sConfirm1"),
            "login" => _("Login") .' : '. $this->logins[0],
            "password" => _("Password") .' : '. $this->password
        ];
        return array_merge($this->getCommonTokens(), $myTokens);
    }

}
