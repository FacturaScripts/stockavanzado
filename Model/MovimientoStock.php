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

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Variante;

/**
 * Description of MovimientoStock
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class MovimientoStock extends ModelClass
{
    use ModelTrait;

    /** @var float */
    public $cantidad;

    /** @var string */
    public $codalmacen;

    /** @var int */
    public $docid;

    /** @var string */
    public $docmodel;

    /** @var string */
    public $documento;

    /** @var string */
    public $fecha;

    /** @var string */
    public $hora;

    /** @var int */
    public $id;

    /** @var int */
    public $idproducto;

    /** @var string */
    public $referencia;

    /** @var float */
    public $saldo;

    public function clear(): void
    {
        parent::clear();
        $this->cantidad = 0.0;
        $this->saldo = 0.0;
        $this->fecha = Tools::date();
        $this->hora = Tools::hour();
    }

    public function getVariant(): Variante
    {
        $variant = new Variante();
        $where = [Where::column('referencia', $this->referencia)];
        $variant->loadWhere($where);
        return $variant;
    }

    public function getProduct(): Producto
    {
        $product = new Producto();
        $product->load($this->idproducto);
        return $product;
    }

    public function install(): string
    {
        new Almacen();
        new Variante();
        return parent::install();
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'stocks_movimientos';
    }

    public function test(): bool
    {
        $this->documento = Tools::noHtml($this->documento);

        return parent::test();
    }

    public function saveInsert(): bool
    {
        if (!parent::saveInsert()) {
            return false;
        }

        // Calculamos el saldo antes de guardar
        $this->calculateSaldo();

        return true;
    }

    /**
     * Calcula y actualiza el saldo de todos los movimientos al insertar un movimiento nuevo.
     * El saldo será la suma de todas las cantidades de movimientos anteriores (fecha, hora, id ASC) más la cantidad de este movimiento.
     */
    private function calculateSaldo(): void
    {
        // Filtrar por producto y almacén
        $where = [
            Where::column('codalmacen', $this->codalmacen),
            Where::column('referencia', $this->referencia)
        ];

        // Seleccionar todos los movimientos en orden cronológico
        $movements = MovimientoStock::all($where, ['fecha' => 'ASC', 'hora' => 'ASC', 'id' => 'ASC']);

        if (empty($movements)) {
            self::$dataBase->exec("UPDATE stocks_movimientos SET saldo = " . self::$dataBase->var2str($this->cantidad) . " WHERE id = " . self::$dataBase->var2str($this->id));
            return;
        }

        $saldo = 0.0;
        foreach ($movements as $movement) {
            $saldo += (float)$movement->cantidad;
            self::$dataBase->exec("UPDATE stocks_movimientos SET saldo = " . self::$dataBase->var2str($saldo) . " WHERE id = " . self::$dataBase->var2str($movement->id()));
        }
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        $modelClass = '\\FacturaScripts\\Dinamic\\Model\\' . $this->docmodel;
        if (!empty($this->docmodel) && class_exists($modelClass)) {
            $model = new $modelClass();
            if ($model->load($this->docid)) {
                return $model->url();
            }
        }

        return empty($this->id()) ? parent::url($type, $list) : $this->getProduct()->url();
    }
}
