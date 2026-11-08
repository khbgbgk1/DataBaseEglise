<?php

namespace EcclesiaCRM\APIControllers;

use Psr\Container\ContainerInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;

use EcclesiaCRM\SessionUser;
use EcclesiaCRM\PersonSacrament;
use EcclesiaCRM\PersonSacramentQuery;

class SacramentController
{
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public static function getSacramentLabels(): array
    {
        return \EcclesiaCRM\dto\SacramentTypes::getAll();
    }

    public function getPersonSacraments(ServerRequest $request, Response $response, array $args): Response
    {
        if (!SessionUser::getUser()->isSeePrivacyDataEnabled()) {
            return $response->withStatus(403);
        }
        $personId = (int)$args['personId'];
        $sacraments = PersonSacramentQuery::create()
            ->filterByPersonId($personId)
            ->orderByDate()
            ->find();

        $labels = self::getSacramentLabels();
        $out = [];
        foreach ($sacraments as $sac) {
            $out[] = [
                'id'     => $sac->getId(),
                'type'   => $sac->getType(),
                'label'  => $labels[$sac->getType()] ?? $sac->getType(),
                'date'   => $sac->getDate() ? $sac->getDate()->format('Y-m-d') : null
            ];
        }
        return $response->withJson($out);
    }

    public function savePersonSacrament(ServerRequest $request, Response $response, array $args): Response
    {
        $user = SessionUser::getUser();
        $personId = (int)$args['personId'];
        $canEdit = $user->isEditRecordsEnabled()
            || ($user->isEditSelfEnabled() && $personId == $user->getPersonId());
        if (!$canEdit) {
            return $response->withStatus(403);
        }

        $input = (object)$request->getParsedBody();
        $type = (string)($input->type ?? '');
        $date = (string)($input->date ?? '');

        $labels = self::getSacramentLabels();
        if (!isset($labels[$type])) {
            return $response->withJson(['status' => 'error', 'message' => _('Unknown sacrament type')], 400);
        }

        $dateObj = null;
        if ($date !== '') {
            $canonical = \EcclesiaCRM\Utils\InputUtils::parseAndValidateDate($date);
            if ($canonical === false) {
                return $response->withJson(['status' => 'error', 'message' => _('Not a valid date')], 400);
            }
            $dateObj = new \DateTime($canonical);
        } elseif ($type !== 'WIDOWHOOD') {
            return $response->withJson(['status' => 'error', 'message' => _('A date is required for this sacrament')], 400);
        }

        $sac = PersonSacramentQuery::create()
            ->filterByPersonId($personId)
            ->findOneByType($type);
        if ($sac === null) {
            $sac = new PersonSacrament();
            $sac->setPersonId($personId);
            $sac->setType($type);
        }
        $sac->setDate($dateObj);
        $sac->save();

        return $response->withJson(['status' => 'success', 'id' => $sac->getId()]);
    }

    public function deletePersonSacrament(ServerRequest $request, Response $response, array $args): Response
    {
        $user = SessionUser::getUser();
        $sac = PersonSacramentQuery::create()->findOneById((int)$args['sacramentId']);
        if ($sac === null) {
            return $response->withStatus(404);
        }
        $personId = $sac->getPersonId();
        $canEdit = $user->isEditRecordsEnabled()
            || ($user->isEditSelfEnabled() && $personId == $user->getPersonId());
        if (!$canEdit) {
            return $response->withStatus(403);
        }
        $sac->delete();
        return $response->withJson(['status' => 'success']);
    }
}
