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
 * Una sesion de conteo para stocks.
 *
 * @author Juan José Prieto Dzul <juanjoseprieto88@gmail.com>
 */
class ConteoStock extends Base\ModelClass
{
    use Base\ModelTrait;

    public $codalmacen;
    public $editable;
    public $idempresa;
    public $idconteo;
    public $nick;

    public function clear()
    {
        parent::clear();

        $this->editable = true;
        $this->fechainicio = date(self::DATE_STYLE);
        $this->horainicio = date(self::HOUR_STYLE);
    }

    public function getAlmacen()
    {
        $almacen = new Almacen();
        $almacen->loadFromCode($this->codalmacen);

        return $almacen;
    }

    public function getEmpresa()
    {
        $empresa = new Empresa();
        $empresa->loadFromCode($this->idempresa);

        return $empresa;
    }

    public function getLines()
    {
        $lineaModel = new LineaConteoStock();
        $where = [new DataBaseWhere('idconteo', $this->idconteo)];
        $order = ['orden' => 'DESC', 'idlinea' => 'ASC'];

        return $lineaModel->all($where, $order, 0, 0);
    }

    public function getUser()
    {
        $user = new User();
        $user->loadFromCode($this->nick);

        return $user;
    }

    public function getCountRecords(string $referencia = '')
    {
        $lineaconteo = new LineaConteoStock();
        $where = [
            new DataBaseWhere('idconteo', $this->idconteo),
            new DataBaseWhere('referencia', $referencia)
        ];
        $order = ['idlinea' => 'ASC'];

        return $lineaconteo->all($where, $order, 0, 0);
    }

    public function loadFromUser($nick = null)
    {
        if (false === empty($nick)) {
            $where = [new DataBaseWhere('nick', $nick)];
            return $this->loadFromCode('', $where);
        }

        return false;
    }

    public static function primaryColumn()
    {
        return 'idconteo';
    }

    public static function tableName()
    {
        return 'stocks_conteos';
    }

    public function allAvailable()
    {
        $where = [new DataBaseWhere('editable', true)];
        return $this->all($where);
    }
}
