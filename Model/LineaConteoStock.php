<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\Variante;
use FacturaScripts\Plugins\StockAvanzado\Lib\StockMovementManager;
use FacturaScripts\Dinamic\Lib\StockRebuild;

/**
 * Description of LineaConteoStock
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class LineaConteoStock extends Base\ModelClass
{
    use Base\ModelTrait;

    /** @var float */
    public $cantidad;

    /** @var string */
    public $fecha;

    /** @var int */
    public $idlinea;

    /** @var int */
    public $idproducto;

    /** @var int */
    public $idconteo;

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

    public function delete(): bool
    {
        // buscamos el movimiento de stock asociado a la línea
        $conteo = $this->getConteo();
        $movement = new MovimientoStock();
        $where = [
            new DataBaseWhere('codalmacen', $conteo->codalmacen),
            new DataBaseWhere('docid', $conteo->primaryColumnValue()),
            new DataBaseWhere('docmodel', $conteo->modelClassName()),
            new DataBaseWhere('referencia', $this->referencia)
        ];

        // si no existe el movimiento, eliminamos la línea
        if (false === $movement->loadFromCode('', $where)) {
            return parent::delete();
        }

        // si no se puede eliminar el movimiento, terminamos
        if (false === $movement->delete()) {
            return false;
        }

        // reconstruimos el stock del producto
        StockRebuild::rebuild($movement->idproducto);

        // finalmente eliminamos la línea
        return parent::delete();
    }

    public function getConteo(): ConteoStock
    {
        $conteo = new ConteoStock();
        $conteo->loadFromCode($this->idconteo);
        return $conteo;
    }

    public function getProducto(): Producto
    {
        $producto = new Producto();
        $producto->loadFromCode($this->idproducto);
        return $producto;
    }

    public function getStock(): Stock
    {
        $stock = new Stock();
        $where = [
            new DataBaseWhere('codalmacen', $this->getConteo()->codalmacen),
            new DataBaseWhere('referencia', $this->referencia)
        ];
        $stock->loadFromCode('', $where);
        return $stock;
    }

    public function getVariant(): Variante
    {
        $variante = new Variante();
        $where = [new DataBaseWhere('referencia', $this->referencia)];
        $variante->loadFromCode('', $where);
        return $variante;
    }

    public static function primaryColumn(): string
    {
        return 'idlinea';
    }

    public function save(): bool
    {
        if (parent::save()) {
            StockMovementManager::updateLineCount($this, $this->getConteo());
            return true;
        }

        return false;
    }

    public static function tableName(): string
    {
        return 'stocks_lineasconteos';
    }
}
