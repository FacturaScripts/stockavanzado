<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2021 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Core\Model\Base;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Variante;

/**
 * Description of MovimientoStock
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class MovimientoStock extends Base\ModelClass
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
    public $codalmacen;

    /**
     *
     * @var int
     */
    public $docid;

    /**
     *
     * @var string
     */
    public $docmodel;

    /**
     *
     * @var string
     */
    public $documento;

    /**
     *
     * @var string
     */
    public $fecha;

    /**
     *
     * @var string
     */
    public $hora;

    /**
     *
     * @var int
     */
    public $id;

    /**
     *
     * @var int
     */
    public $idproducto;

    /**
     *
     * @var string
     */
    public $referencia;

    public function clear()
    {
        parent::clear();
        $this->cantidad = 0.0;
        $this->fecha = \date(self::DATE_STYLE);
        $this->hora = \date(self::HOUR_STYLE);
    }

    /**
     * 
     * @return bool
     */
    public function deleteAll()
    {
        return self::$dataBase->exec('DELETE FROM ' . static::tableName() . ';');
    }

    /**
     * 
     * @return Variante
     */
    public function getVariant()
    {
        $variant = new Variante();
        $where = [new DataBaseWhere('referencia', $this->referencia)];
        $variant->loadFromCode('', $where);
        return $variant;
    }

    /**
     * 
     * @return Producto
     */
    public function getProduct()
    {
        $product = new Producto();
        $product->loadFromCode($this->idproducto);
        return $product;
    }

    /**
     * 
     * @return string
     */
    public static function primaryColumn(): string
    {
        return 'id';
    }

    /**
     * 
     * @return string
     */
    public static function tableName(): string
    {
        return 'stocks_movimientos';
    }

    /**
     * 
     * @return bool
     */
    public function test()
    {
        $this->documento = $this->toolBox()->utils()->noHtml($this->documento);
        return parent::test();
    }

    /**
     * 
     * @param string $type
     * @param string $list
     *
     * @return string
     */
    public function url(string $type = 'auto', string $list = 'List'): string
    {
        $modelClass = '\\FacturaScripts\\Dinamic\\Model\\' . $this->docmodel;
        if (!empty($this->docmodel) && \class_exists($modelClass)) {
            $model = new $modelClass();
            if ($model->loadFromCode($this->docid)) {
                return $model->url();
            }
        }

        return empty($this->primaryColumnValue()) ? parent::url($type, $list) : $this->getProduct()->url();
    }
}
