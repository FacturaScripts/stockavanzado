<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2014-2026 Carlos García Gómez <carlos@facturascripts.com>
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
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\TransferenciaStock as DinTransferenciaStock;
use FacturaScripts\Dinamic\Model\Variante;

/**
 * @author Cristo M. Estévez Hernández  <cristom.estevez@gmail.com>
 * @author Carlos García Gómez          <carlos@facturascripts.com>
 */
class LineaTransferenciaStock extends ModelClass
{
    use ModelTrait;
    use ProductRelationTrait;

    /** @var bool */
    public $bypassCompletedCheck = false;

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

    public function clear(): void
    {
        parent::clear();
        $this->cantidad = 1.0;
        $this->fecha = Tools::dateTime();
        $this->nick = Session::user()->nick;
    }

    public function delete(): bool
    {
        if (false === $this->bypassCompletedCheck) {
            $transfer = $this->getTransference();
            if ($transfer->exists() && $transfer->completed) {
                Tools::log()->warning('cannot-modify-completed-transfer');
                return false;
            }
        }

        return parent::delete();
    }

    public function getStockDest(): Stock
    {
        $stock = new Stock();
        $where = [
            Where::eq('codalmacen', $this->getTransference()->codalmacendestino),
            Where::eq('referencia', $this->referencia)
        ];
        $stock->loadWhere($where);
        return $stock;
    }

    public function getStockOrig(): Stock
    {
        $stock = new Stock();
        $where = [
            Where::eq('codalmacen', $this->getTransference()->codalmacenorigen),
            Where::eq('referencia', $this->referencia)
        ];
        $stock->loadWhere($where);
        return $stock;
    }

    public function getTransference(): DinTransferenciaStock
    {
        $trans = new DinTransferenciaStock();
        $trans->load($this->idtrans);
        return $trans;
    }

    public function getVariant(): Variante
    {
        $variant = new Variante();
        $variant->loadWhereEq('referencia', $this->referencia);
        return $variant;
    }

    public function install(): string
    {
        // dependencias
        new DinTransferenciaStock();
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
            $this->idproducto = $this->getVariant()->idproducto;
        }

        $transfer = $this->getTransference();
        if ($transfer->exists() && $transfer->completed) {
            Tools::log()->warning('cannot-modify-completed-transfer');
            return false;
        }

        if ($this->cantidad <= 0) {
            Tools::log()->warning('quantity-must-be-positive');
            return false;
        }

        $product = new Producto();
        if ($product->load($this->idproducto) && $product->nostock) {
            Tools::log()->warning('no-stock-this-product', ['%referencia%' => $this->referencia]);
            return false;
        }

        return parent::test();
    }

    public static function tableName(): string
    {
        return 'stocks_lineastransferencias';
    }
}
