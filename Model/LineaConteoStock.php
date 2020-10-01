<?php
/**
 * This file is part of Inventory plugin for FacturaScripts
 * Copyright (C) 2019 Juan José Prieto Dzul <juanjoseprieto88@gmail.com>
 */
namespace FacturaScripts\Plugins\ConteoInventario\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\User;

/**
 * Una terminal POS.
 *
 * @author Juan José Prieto Dzul <juanjoseprieto88@gmail.com>
 */
class LineaConteoStock extends Base\ModelClass
{
    use Base\ModelTrait;

    public $cantidad;
    public $editable;
    public $fechaconteo;
    public $horaconteo;
    public $idconteo;
    public $referencia;

    public function clear()
    {
        parent::clear();

        $this->cantidad = 1;
        $this->pendiente = true;
        $this->fechaconteo = date(self::DATE_STYLE);
        $this->horaconteo = date(self::HOUR_STYLE);
    }

    public function getConteo()
    {
        $conteo = new ConteoStock();
        $conteo->loadFromCode($this->idconteo);

        return $conteo;
    }

    public static function primaryColumn()
    {
        return 'idlinea';
    }

    public static function tableName()
    {
        return 'stocks_lineasconteos';
    }
}
