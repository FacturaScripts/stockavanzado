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

namespace FacturaScripts\Plugins\StockAvanzado\Model\Join;

use FacturaScripts\Core\Model\Base\JoinModel;
use FacturaScripts\Dinamic\Model\Producto;

/**
 * Description of StockProducto
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class StockProducto extends JoinModel
{
    /**
     * Class constructor.
     * Set master model for controller actions.
     *
     * @param array $data
     */
    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->setMasterModel(new Producto());
    }

    protected function getFields(): array
    {
        return [
            'bloqueado' => 'productos.bloqueado',
            'cantidad' => 'stocks.cantidad',
            'codalmacen' => 'stocks.codalmacen',
            'codfabricante' => 'productos.codfabricante',
            'codfamilia' => 'productos.codfamilia',
            'coste' => 'variantes.coste',
            'descripcion' => 'productos.descripcion',
            'disponible' => 'stocks.disponible',
            'falta_sobra' => 'stocks.pterecibir + stocks.cantidad - stocks.reservada',
            'idproducto' => 'stocks.idproducto',
            'idstock' => 'stocks.idstock',
            'nostock' => 'productos.nostock',
            'precio' => 'variantes.precio',
            'pterecibir' => 'stocks.pterecibir',
            'referencia' => 'stocks.referencia',
            'reservada' => 'stocks.reservada',
            'stockmax' => 'stocks.stockmax',
            'stockmin' => 'stocks.stockmin',
            'total_coste' => 'stocks.cantidad*variantes.coste',
            'total_precio' => 'stocks.cantidad*variantes.precio',
            'total_movimientos' => 'COALESCE(SUM(stocks_movimientos.cantidad), 0)',
            'tipo' => 'productos.tipo',
        ];
    }

    protected function getGroupFields(): string
    {
        return 'productos.bloqueado, stocks.cantidad, stocks.codalmacen, productos.codfabricante, '
            . 'productos.codfamilia, variantes.coste, productos.descripcion, stocks.disponible, '
            . 'stocks.pterecibir, stocks.reservada, stocks.idproducto, stocks.idstock, '
            . 'productos.nostock, variantes.precio, stocks.referencia, stocks.stockmax, '
            . 'stocks.stockmin, productos.tipo, variantes.referencia';
    }

    protected function getSQLFrom(): string
    {
        return 'variantes'
            . ' LEFT JOIN stocks_movimientos ON variantes.referencia = stocks_movimientos.referencia'
            . ' LEFT JOIN stocks ON variantes.referencia = stocks.referencia'
            . ' LEFT JOIN productos ON productos.idproducto = variantes.idproducto';
    }

    protected function getTables(): array
    {
        return ['productos', 'stocks', 'variantes', 'stocks_movimientos'];
    }
}
