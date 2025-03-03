<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2014-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Dinamic\Model\TransferenciaStock as DinTransferenciaStock;
use FacturaScripts\Dinamic\Model\Variante;

/**
 * @author Cristo M. Estévez Hernández  <cristom.estevez@gmail.com>
 * @author Carlos Garcia Gomez          <carlos@facturascripts.com>
 */
class LineaTransferenciaStock extends Base\ModelClass
{
    use Base\ModelTrait;
    use Base\ProductRelationTrait;

    /** @var float */
    public $cantidad;

    /** @var string */
    public $fecha;

    /** @var int */
    public $idlinea;

    /** @var int */
    public $idtrans;

    /** @var string */
    public $nick;

    /** @var string */
    public $referencia;

    public function clear()
    {
        parent::clear();
        $this->cantidad = 1.0;
        $this->fecha = Tools::dateTime();
        $this->nick = Session::user()->nick;
    }

    public function getTransference(): DinTransferenciaStock
    {
        $trans = new DinTransferenciaStock();
        $trans->loadFromCode($this->idtrans);
        return $trans;
    }

    public function getVariant(): Variante
    {
        $variant = new Variante();
        $where = [new DataBaseWhere('referencia', $this->referencia)];
        $variant->loadFromCode('', $where);
        return $variant;
    }

    public function install(): string
    {
        // needed dependencies
        new TransferenciaStock();
        new Variante();

        return parent::install();
    }

    public static function primaryColumn(): string
    {
        return 'idlinea';
    }

    public function test(): bool
    {
        $this->fecha = Tools::dateTime();
        $this->nick = Session::user()->nick;

        if (empty($this->idproducto)) {
            $variant = $this->getVariant();
            $this->idproducto = $variant->idproducto;
        }

        return parent::test();
    }

    public static function tableName(): string
    {
        return 'stocks_lineastransferencias';
    }
}
