<?php

//
//  This code is under copyright not under MIT Licence
//  copyright   : 2022 Philippe Logel all right reserved not MIT licence
//                This code can't be included in another software
//
//  Updated : 2022/01/06
//

namespace EcclesiaCRM\VIEWControllers;

use Slim\Http\Response;
use Slim\Http\ServerRequest;
use Psr\Container\ContainerInterface;

use EcclesiaCRM\dto\SystemConfig;
use EcclesiaCRM\PersonQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use EcclesiaCRM\dto\SystemURLs;
use EcclesiaCRM\SessionUser;


use Slim\Views\PhpRenderer;

class VIEWPersonListController {

    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function renderPersonList (ServerRequest $request, Response $response, array $args): Response {
        $renderer = new PhpRenderer('templates/people/');

        $sMode = $args['mode'];

        if ( !( SessionUser::getUser()->isEditRecordsEnabled()
            || (strtolower($sMode)  == 'gdrp' && SessionUser::getUser()->isGdrpDpoEnabled())
            || (strtolower($sMode) == 'inactive' && SessionUser::getUser()->isEditRecordsEnabled())
        )
        ) {
            return $response->withStatus(302)->withHeader('Location', SystemURLs::getRootPath() . '/v2/dashboard');
        }

        $params = $request->getQueryParams();

        return $renderer->render($response, 'personlist.php', $this->argumentsPersonListArray($sMode, $params));
    }

    public function argumentsPersonListArray ($sMode='Active', array $params=[])
    {
        if (strtolower($sMode) == 'gdrp') {
            $time = new \DateTime('now');
            $newtime = $time->modify('-'.SystemConfig::getValue('iGdprExpirationDate').' year')->format('Y-m-d');

            $persons = PersonQuery::create()
                ->filterByDateDeactivated($newtime, Criteria::LESS_THAN)// GDRP, when a person is completely deactivated
                ->_or() // or : this part is unusefull, it's only for debugging
                ->useFamilyQuery()
                ->filterByDateDeactivated($newtime, Criteria::LESS_THAN)// GDRP, when a Family is completely deactivated
                ->endUse()
                ->orderByLastName();

        } else if (strtolower($sMode) == 'inactive') {
            if (SystemConfig::getBooleanValue('bGDPR')) {
                $time = new \DateTime('now');
                $newtime = $time->modify('-'.SystemConfig::getValue('iGdprExpirationDate').' year')->format('Y-m-d');

                $persons = PersonQuery::create()
                    ->filterByDateDeactivated($newtime, Criteria::GREATER_THAN)// GDRP, when a person isn't under GDRP but deactivated, we only can see the person who are over a certain date
                    ->_or()// this part is unusefull, it's only for debugging
                    ->useFamilyQuery()
                    ->filterByDateDeactivated($newtime, Criteria::GREATER_THAN)// GDRP, when a Family is completely deactivated
                    ->endUse()
                    ->orderByLastName();
            } else {
                $time = new \DateTime('now');

                $persons = PersonQuery::create()
                    ->filterByDateDeactivated($time, Criteria::LESS_EQUAL)
                    ->orderByLastName();
            }
        } else {
            $sMode = 'Active';
            $persons = PersonQuery::create()
                ->filterByDateDeactivated(null)
                ->orderByLastName();
        }

        $persons = $this->applySacramentFilters($persons, $params)->find();

        $sPageTitle = _(ucfirst(_($sMode))) . ' : ' . _('Person List');

        $sRootDocument   = SystemURLs::getDocumentRoot();
        $sDateFormatLong = SystemConfig::getValue('sDateFormatLong');
        $sCSPNonce       = SystemURLs::getCSPNonce();

        $paramsArguments = ['sRootPath'           => SystemURLs::getRootPath(),
            'sRootDocument'        => $sRootDocument,
            'sPageTitle'           => '<i class="fas fa-users text-primary"></i>&nbsp;' . $sPageTitle,
            'sMode'                => $sMode,
            'sCSPNonce'            => $sCSPNonce,
            'persons'              => $persons,
            'bNotGDRP'             => SessionUser::getUser()->isAddRecordsEnabled() && strtolower($sMode) != 'gdrp'
        ];

        return $paramsArguments;
    }

    /**
     * Applies the server-side sacrament filters (presence/absence + date
     * range/year) on top of the already-built PersonQuery.
     *
     * NOTE on Propel behaviour: each filter is added via a SEPARATE where()
     * call (explicit logical AND). This fork's Propel uses a RawCriterion for
     * typed "WHERE expression" clauses, which accepts ONLY ONE '?' placeholder
     * per clause (otherwise an InvalidClauseException is thrown at SQL build
     * time). Therefore the plan's "one where() call with implode(' AND ', ...)"
     * cannot be used with two or more simultaneous filters.
     */
    private function applySacramentFilters(PersonQuery $q, array $paramsQ): PersonQuery
    {
        $codes = \EcclesiaCRM\dto\SacramentTypes::getCodes();

        $year = isset($paramsQ['sac_date_year']) ? trim($paramsQ['sac_date_year']) : '';
        $from = isset($paramsQ['sac_date_from']) ? trim($paramsQ['sac_date_from']) : '';
        $to   = isset($paramsQ['sac_date_to'])   ? trim($paramsQ['sac_date_to'])   : '';

        $dateConds = [];
        if (preg_match('/^\\d{4}$/', $year)) {
            $dateConds[] = "(sacrament_date >= '$year-01-01' AND sacrament_date <= '$year-12-31')";
        }
        if ($from !== '' && ($dFrom = \EcclesiaCRM\Utils\InputUtils::parseAndValidateDate($from)) !== false) {
            $dateConds[] = "(sacrament_date >= '$dFrom')";
        }
        if ($to !== '' && ($dTo = \EcclesiaCRM\Utils\InputUtils::parseAndValidateDate($to)) !== false) {
            $dateConds[] = "(sacrament_date <= '$dTo')";
        }
        $dateSql = empty($dateConds) ? '' : (' AND ' . implode(' AND ', $dateConds));

        foreach ($codes as $code) {
            $key = 'sac_' . $code;
            if (!isset($paramsQ[$key])) {
                continue;
            }
            $val = $paramsQ[$key];
            if ($val === '1') {
                $q = $q->where("(EXISTS (SELECT 1 FROM person_sacrament WHERE sacrament_person_id = person_per.per_ID AND sacrament_type = ?$dateSql))", $code, \PDO::PARAM_STR);
            } elseif ($val === '0') {
                $q = $q->where("(NOT EXISTS (SELECT 1 FROM person_sacrament WHERE sacrament_person_id = person_per.per_ID AND sacrament_type = ?$dateSql))", $code, \PDO::PARAM_STR);
            }
        }
        return $q;
    }

}
