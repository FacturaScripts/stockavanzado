<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Core\Model\Base\ProductRelationTrait;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\ConteoStock as DinConteoStock;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\Variante;

/**
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class LineaConteoStock extends ModelClass
{
    use ModelTrait;
    use ProductRelationTrait;

    /** @var float */
    public $cantidad;

    /** @var string */
    public $fecha;

    /** @var int */
    public $idlinea;

    /** @var int */
    public $idconteo;

    /** @var string */
    public $nick;

    /** @var string */
    public $referencia;

    public function clear(): void
    {
        parent::clear();
        $this->cantidad = 1.0;
        $this->fecha = Tools::dateTime();
        $this->nick = Session::user()->nick;
    }

    public function getConteo(): DinConteoStock
    {
        $conteo = new DinConteoStock();
        $conteo->load($this->idconteo);
        return $conteo;
    }

    public function getStock(): Stock
    {
        $stock = new Stock();
        $where = [
            Where::eq('codalmacen', $this->getConteo()->codalmacen),
            Where::eq('referencia', $this->referencia)
        ];
        $stock->loadWhere($where);
        return $stock;
    }

    public function getVariant(): Variante
    {
        $variante = new Variante();
        $variante->loadWhereEq('referencia', $this->referencia);
        return $variante;
    }

    public function install(): string
    {
        // dependencias
        new Variante();
        new DinConteoStock();

        return parent::install();
    }

    public static function primaryColumn(): string
    {
        return 'idlinea';
    }

    public static function tableName(): string
    {
        return 'stocks_lineasconteos';
    }

    public function test(): bool
    {
        $this->fecha = Tools::dateTime();
        $this->nick = Session::user()->nick;

        if (empty($this->idproducto)) {
            $this->idproducto = $this->getVariant()->idproducto;
        }

        return parent::test();
    }
}
