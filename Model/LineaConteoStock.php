<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\Variante;
use FacturaScripts\Plugins\StockAvanzado\Lib\StockMovementManager;

/**
 * Description of LineaConteoStock
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class LineaConteoStock extends Base\ModelClass
{

    use Base\ModelTrait;

    /**
     *
     * @var float
     */
    public $cantidad;

    /**
     *
     * @var string
     */
    public $fecha;

    /**
     *
     * @var int
     */
    public $idlinea;

    /**
     *
     * @var int
     */
    public $idproducto;

    /**
     *
     * @var int
     */
    public $idconteo;

    /**
     *
     * @var string
     */
    public $nick;

    /**
     *
     * @var string
     */
    public $referencia;

    public function clear()
    {
        parent::clear();
        $this->cantidad = 1.0;
        $this->fecha = \date(self::DATETIME_STYLE);
    }

    /**
     * 
     * @return bool
     */
    public function delete()
    {
        if (parent::delete()) {
            $this->cantidad = 0.0;
            StockMovementManager::updateLineCount($this, $this->getConteo());
            return true;
        }

        return false;
    }

    /**
     * 
     * @return ConteoStock
     */
    public function getConteo()
    {
        $conteo = new ConteoStock();
        $conteo->loadFromCode($this->idconteo);
        return $conteo;
    }

    /**
     * 
     * @return Producto
     */
    public function getProducto()
    {
        $producto = new Producto();
        $producto->loadFromCode($this->idproducto);
        return $producto;
    }

    /**
     * 
     * @return Stock
     */
    public function getStock()
    {
        $stock = new Stock();
        $where = [
            new DataBaseWhere('codalmacen', $this->getConteo()->codalmacen),
            new DataBaseWhere('referencia', $this->referencia)
        ];
        $stock->loadFromCode('', $where);
        return $stock;
    }

    /**
     * 
     * @return Variante
     */
    public function getVariant()
    {
        $variante = new Variante();
        $where = [new DataBaseWhere('referencia', $this->referencia)];
        $variante->loadFromCode('', $where);
        return $variante;
    }

    /**
     * 
     * @return string
     */
    public static function primaryColumn(): string
    {
        return 'idlinea';
    }

    /**
     * 
     * @return bool
     */
    public function save()
    {
        if (parent::save()) {
            StockMovementManager::updateLineCount($this, $this->getConteo());
            return true;
        }

        return false;
    }

    /**
     * 
     * @return string
     */
    public static function tableName(): string
    {
        return 'stocks_lineasconteos';
    }
}
