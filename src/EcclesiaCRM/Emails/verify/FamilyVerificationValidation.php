<?php

/*******************************************************************************
 *
 *  filename    : Emails/verify/FamilyVerificationValidation.php
 *  last change : 2024-01-31 Philippe Logel
 *  description : Create emails with all the confirmation letters asking member
 *                families to verify the information in the database.
 *
 ******************************************************************************/

namespace EcclesiaCRM\Emails;

class FamilyVerificationValidation extends BaseEmail
{
    private $token;
    protected $familyName;
    protected $message;
    protected $familyId;

    public function __construct($emails, $familyName, $token = "", $message = "", $familyId=0)
    {
        $this->familyName = $familyName;
        $this->token = $token;
        $this->message = $message;
        $this->familyId = $familyId;

        parent::__construct($emails);

        $this->mail->Subject = _("Family"). " : ". $familyName . " (" . gettext("informations").")";
        $this->mail->isHTML(true);
        $this->mail->msgHTML($this->buildMessage());
    }

    public function getTokens()
    {
        $myTokens = ["toName" => $this->familyName . " " . gettext("Family"),
            "verificationValidation" => $this->token,
            "familyId" => $this->familyId,
            "body" => $this->message,
        ];
        return array_merge($this->getCommonTokens(), $myTokens);
    }

}
