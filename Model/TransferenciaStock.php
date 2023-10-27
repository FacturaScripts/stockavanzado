<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2013-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\StockAvanzado\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Almacen;

/**
 * The head of transfer.
 *
 * @author Cristo M. Estévez Hernández  <cristom.estevez@gmail.com>
 * @author Carlos García Gómez          <carlos@facturascripts.com>
 */
class TransferenciaStock extends Base\ModelClass
{
    use Base\ModelTrait;

    /**
     * Warehouse of destination. Varchar (4).
     *
     * @var string
     */
    public $codalmacendestino;

    /**
     * Warehouse of origin. Varchar (4).
     *
     * @var string
     */
    public $codalmacenorigen;

    /**
     * Date of transfer.
     *
     * @var string
     */
    public $fecha;

    /**
     * Primary key autoincrement.
     *
     * @var int
     */
    public $idtrans;

    /**
     * User of transfer action. Varchar (50).
     *
     * @var string
     */
    public $nick;

    /**
     * @var string
     */
    public $observaciones;

    public function clear()
    {
        parent::clear();
        $this->fecha = Tools::dateTime();
        $this->nick = Session::user()->nick;
    }

    public function delete(): bool
    {
        $newTransaction = false === self::$dataBase->inTransaction() && self::$dataBase->beginTransaction();

        // remove lines to force update stock
        foreach ($this->getLines() as $line) {
            $line->delete();
        }

        if (parent::delete()) {
            if ($newTransaction) {
                self::$dataBase->commit();
            }
            return true;
        }

        if ($newTransaction) {
            self::$dataBase->rollback();
        }

        return false;
    }

    /**
     * @return LineaTransferenciaStock[]
     */
    public function getLines(): array
    {
        $line = new LineaTransferenciaStock();
        $where = [new DataBaseWhere('idtrans', $this->primaryColumnValue())];
        return $line->all($where, [], 0, 0);
    }

    public static function primaryColumn(): string
    {
        return 'idtrans';
    }

    public static function tableName(): string
    {
        return 'stocks_transferencias';
    }

    public function test(): bool
    {
        $this->observaciones = Tools::noHtml($this->observaciones);

        if ($this->codalmacenorigen == $this->codalmacendestino) {
            Tools::log()->warning('warehouse-cant-be-same');
            return false;
        }

        if ($this->getIdempresa($this->codalmacendestino) !== $this->getIdempresa($this->codalmacenorigen)) {
            Tools::log()->warning('warehouse-must-be-same-business');
            return false;
        }

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'ListAlmacen?activetab=List'): string
    {
        return parent::url($type, $list);
    }

    /**
     * @param string $codalmacen
     *
     * @return int
     */
    protected function getIdempresa($codalmacen)
    {
        $warehouse = new Almacen;
        $warehouse->loadFromCode($codalmacen);
        return $warehouse->idempresa;
    }
}
