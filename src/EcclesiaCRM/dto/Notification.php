<?php

namespace EcclesiaCRM\dto;

use EcclesiaCRM\Person;
use EcclesiaCRM\dto\SystemConfig;
use EcclesiaCRM\Emails\NotificationEmail;
use EcclesiaCRM\dto\OpenLPNotification;
use GuzzleHttp\Client;
use EcclesiaCRM\Utils\LoggerUtils;

class Notification
{
  
  protected $projectorText;
  protected $recipients;
  protected $person;
 
  public function __construct()
  {
  
  }
  
  public function setRecipients($recipients)
  {
    $this->recipients = $recipients;
    
  }
  
  public function setSMSText($text)
  {
    
  }
  
  public function setEmailText($text)
  {
    
  }
  
  public function setPerson(\EcclesiaCRM\Person $Person)
  {
    $this->person  = $Person;
  }
  
  public function setProjectorText($text)
  {
    $this->projectorText=$text;
  }
  
  private function sendEmail()
  {
    $emailaddresses = [];
    Foreach ($this->recipients as $recipient)
    {        
      array_push($emailaddresses,$recipient->getEmail());
    }
    try
    {
      $email = new NotificationEmail($emailaddresses,$this->person->getFullName());
      $emailStatus=$email->send();
      return $emailStatus;
    } catch (\Exception $ex) {
      return false;
    }
    
  }
  
  private function sendSMS()
  {
    try
      {
        $apiKey = SystemConfig::getValue("sNexmoAPIKey");
        $apiSecret = SystemConfig::getValue("sNexmoAPISecret");
        $from = SystemConfig::getValue("sNexmoFromNumber");

        $client = new Client([
            'base_uri' => 'https://rest.nexmo.com',
            'timeout'  => 10
        ]);

        $allOk = true;
        Foreach ($this->recipients as $recipient)
        {
          $response = $client->post('/sms/json', [
              'form_params' => [
                  'api_key'    => $apiKey,
                  'api_secret' => $apiSecret,
                  'to'         => $recipient->getNumericCellPhone(),
                  'from'       => $from,
                  'text'       => gettext('Notification for') . " " . $this->person->getFullName()
              ]
          ]);

          $result = json_decode((string)$response->getBody(), true);
          $status = ($result['messages'][0]['status'] ?? '1');
          if ($status != '0') {
              $allOk = false;
          }
        }
        return $allOk;
      } catch (\Exception $ex) {
        LoggerUtils::getAppLogger()->error("SMS send failed: " . $ex->getMessage());
        return false;
      }

  }
  
  private function sendProjector()
  {
    try
      {
        $OLPAlert = new OpenLPNotification(SystemConfig::getValue("sOLPURL"),
                SystemConfig::getValue("sOLPUserName"),
                SystemConfig::getValue("sOLPPassword"));
        $OLPAlert->setAlertText($this->projectorText);
        return $OLPAlert->send();
      } catch (\Exception $ex) {
        return false;
      }
       
  }
  
  public function send()
  {
   
    $methods = [];
    $sendStatus = [
        "status"=>"",
        "methods"=>[]
    ];

    if(SystemConfig::hasValidMailServerSettings())
    {
      $send = $this->sendEmail();
      array_push($methods,"email: ".$send);
    }
    if (SystemConfig::hasValidSMSServerSettings())
    {
      $send = (boolean)$this->sendSMS();
      array_push($methods,"sms: ".$send);
    }
    if(SystemConfig::hasValidOpenLPSettings())
    {
      $send = (boolean)($this->sendProjector());
      array_push($methods,"projector: ".$send);
    }

    $sendStatus['status'] = true;
    $sendStatus['methods'] = $methods;    
    
    return $sendStatus;
    
  }
  
}
