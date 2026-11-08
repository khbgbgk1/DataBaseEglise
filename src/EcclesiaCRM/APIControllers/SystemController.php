<?php

//
//  This code is under copyright not under MIT Licence
//  copyright   : 2021 Philippe Logel all right reserved not MIT licence
//                This code can't be included in another software
//
//  Updated : 2021/04/06
//

namespace EcclesiaCRM\APIControllers;


use Psr\Container\ContainerInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;

use EcclesiaCRM\dto\SystemURLs;
use EcclesiaCRM\SessionUser;

use PHPMailer\PHPMailer\PHPMailer;
use EcclesiaCRM\dto\SystemConfig;
use EcclesiaCRM\dto\ChurchMetaData;
use GuzzleHttp\Client;
use EcclesiaCRM\Utils\LoggerUtils;


class SystemController
{
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function cspReport (ServerRequest $request, Response $response, array $args): Response
    {
        $input = json_decode($request->getBody());
        $log  = json_encode($input, JSON_PRETTY_PRINT);

        $Logger = $this->container->get('Logger');
        $Logger->warn($log);

        return $response;
    }

    public function deleteFile (ServerRequest $request, Response $response, array $args): Response
    {
        $params = (object)$request->getParsedBody();

        if ( !(SessionUser::getUser()->isExportSundaySchoolPDFEnabled() 
            || SessionUser::getUser()->isManageGroupsEnabled() || $_SESSION['bManageGroups']
            || SessionUser::getUser()->isShowCartEnabled() )) {
            return $response->withStatus(401);
            }

        if ( isset ($params->name) ) {
            if (unlink(SystemURLs::getDocumentRoot().'/Images/background/'.basename($params->name))) {
                return $response->withJson(['status' => "success"]);
            }
        }

        return $response->withJson(['status' => "failed"]);
    }

    // test connection
    public function testEmailConnectionMVC (ServerRequest $request, Response $response, array $args): Response
    {
        if (!(SessionUser::getUser()->isAdmin())) {
            return $response->withStatus(401);
        }

        $mailer = new PHPMailer();
        $message = "";
        if (!empty(SystemConfig::getValue("sSMTPHost")) && !empty(ChurchMetaData::getChurchEmail())) {
            $mailer->IsSMTP();
            $mailer->CharSet = 'UTF-8';
            $mailer->Timeout = intval(SystemConfig::getValue("iSMTPTimeout"));
            $res = explode(":", SystemConfig::getValue("sSMTPHost"));
            $mailer->Host = $res[0];
            $mailer->Port  = intval($res[1]);
            if (SystemConfig::getBooleanValue("bSMTPAuth")) {
                $mailer->SMTPAuth = true;
                $result = "<b>SMTP Auth Used</b></br></br>";
                $mailer->Username = SystemConfig::getValue("sSMTPUser");
                $mailer->Password = SystemConfig::getValue("sSMTPPass");
            }
            $mailer->SMTPDebug = 3;
            $mailer->Subject = "Test SMTP Email";
            $mailer->setFrom(ChurchMetaData::getChurchEmail());
            $mailer->addAddress(ChurchMetaData::getChurchEmail());
            $mailer->Body = "test email";
            $mailer->Debugoutput = "html";
        } else {
            $message = _("SMTP Host is not setup, please visit the settings page");
        }

        if (empty($message)) {
            ob_start();
            $mailer->send();
            $result .= ob_get_clean();
            ob_end_flush();
            return $response->withJson(['success' => true,"result" => $result]);
        } else {
            return $response->withJson(['success' => false,"error" => $message]);
        }
    }

    
    public function saveSMSSettingsMVC (ServerRequest $request, Response $response, array $args): Response
    {
        if (!(SessionUser::getUser()->isAdmin())) {
            return $response->withStatus(401);
        }

        $params = (object)$request->getParsedBody();

        if (isset($params->apiKey) && isset($params->apiSecret) && isset($params->fromNumber)) {
            SystemConfig::setValue("sNexmoAPIKey", $params->apiKey);
            SystemConfig::setValue("sNexmoAPISecret", $params->apiSecret);
            SystemConfig::setValue("sNexmoFromNumber", $params->fromNumber);

            return $response->withJson(["status" => "success"]);
        }

        return $response->withJson(["status" => "failed"]);
    }

    public function testSMSMVC (ServerRequest $request, Response $response, array $args): Response
    {
        if (!(SessionUser::getUser()->isAdmin())) {
            return $response->withStatus(401);
        }

        $params = (object)$request->getParsedBody();
        $to = isset($params->to) ? $params->to : null;

        try {
            if (!SystemConfig::hasValidSMSServerSettings()) {
                return $response->withJson(["success" => false, "error" => _("SMS settings are not configured, please visit the SMS settings page")]);
            }

            if (empty($to)) {
                return $response->withJson(["success" => false, "error" => _("Please provide a phone number to send the test SMS to")]);
            }

            $client = new Client([
                'base_uri' => 'https://rest.nexmo.com',
                'timeout'  => 10
            ]);

            $r = $client->post('/sms/json', [
                'form_params' => [
                    'api_key'    => SystemConfig::getValue("sNexmoAPIKey"),
                    'api_secret' => SystemConfig::getValue("sNexmoAPISecret"),
                    'to'         => $to,
                    'from'       => SystemConfig::getValue("sNexmoFromNumber"),
                    'text'       => _("This is a test SMS from EcclesiaCRM")
                ]
            ]);

            $result = json_decode((string)$r->getBody(), true);
            $status = ($result['messages'][0]['status'] ?? '1');

            if ($status == '0') {
                return $response->withJson(["success" => true, "result" => _("Test SMS sent to ") . $to]);
            }

            LoggerUtils::getAppLogger()->error("SMS test failed: " . json_encode($result));
            return $response->withJson(["success" => false, "error" => _("SMS sending failed") . " : " . ($result['messages'][0]['error-text'] ?? '')]);
        } catch (\Exception $ex) {
            LoggerUtils::getAppLogger()->error("SMS test exception: " . $ex->getMessage());
            return $response->withJson(["success" => false, "error" => _("SMS test failed") . " : " . $ex->getMessage()]);
        }
    }

    public function currentPage (ServerRequest $request, Response $response, array $args): Response
    {
        if (!(SessionUser::getUser()->isAdmin())) {
            return $response->withStatus(401);
        }

        $params = (object)$request->getParsedBody();
        if ( !empty(isset ($params->uri)) ) {
            $_SERVER['REQUEST_URI'] = $params->uri;
            SessionUser::setCurrentPageName($_SERVER['REQUEST_URI']);            

            return $response->withJson(['success' => true]);
        }

        return $response->withJson(['success' => false]);        
    }
}
