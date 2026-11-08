<?php

namespace EcclesiaCRM\dto;

class SacramentTypes
{
    public static function getAll(): array
    {
        return [
            'BAPTISM'             => 'Baptême',
            'FIRST_COMMUNION'     => 'Première Communion',
            'CONFIRMATION'        => 'Confirmation',
            'MARRIAGE'            => 'Mariage',
            'ORDINATION_DEACON'   => 'Ordination diaconale',
            'ORDINATION_PRIEST'   => 'Ordination presbytérale',
            'ORDINATION_BISHOP'   => 'Ordination épiscopale',
            'WIDOWHOOD'           => 'Veuf/Veuve'
        ];
    }

    public static function getCodes(): array
    {
        return array_keys(self::getAll());
    }
}
