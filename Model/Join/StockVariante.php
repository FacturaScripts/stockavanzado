<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2026 Carlos García Gómez <carlos@facturascripts.com>
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

use FacturaScripts\Core\Template\JoinModel;
use FacturaScripts\Dinamic\Model\Producto;

/**
 * Description of StockVariante
 *
 * @author Carlos García Gómez <carlos@facturascripts.com>
 */
class StockVariante extends JoinModel
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
            'referencia' => 'variantes.referencia',
            'reservada' => 'stocks.reservada',
            'stockmax' => 'stocks.stockmax',
            'stockmin' => 'stocks.stockmin',
            'total_coste' => 'stocks.cantidad*variantes.coste',
            'total_precio' => 'stocks.cantidad*variantes.precio',
            // subconsulta correlacionada en lugar de JOIN + SUM: evita el GROUP BY que obligaba
            // a JoinModel::count() a materializar una fila por variante en memoria
            'total_movimientos' => '(SELECT COALESCE(SUM(sm.cantidad), 0) FROM stocks_movimientos sm'
                . ' WHERE sm.referencia = variantes.referencia)',
            'tipo' => 'productos.tipo',
        ];
    }

    protected function getSQLFrom(): string
    {
        return 'variantes'
            . ' LEFT JOIN stocks ON variantes.referencia = stocks.referencia'
            . ' LEFT JOIN productos ON productos.idproducto = variantes.idproducto';
    }

    protected function getTables(): array
    {
        return ['productos', 'stocks', 'variantes', 'stocks_movimientos'];
    }
}
