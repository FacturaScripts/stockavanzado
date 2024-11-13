<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2022 Carlos Garcia Gomez <carlos@facturascripts.com>
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
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class MovimientoProducto extends JoinModel
{
    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->setMasterModel(new Producto());
    }

    protected function getFields(): array
    {
        return [
            'cantidad' => 'sum(sm.cantidad)',
            'descripcion' => 'p.descripcion',
            'idproducto' => 'p.idproducto',
            'referencia' => 'v.referencia'
        ];
    }

    protected function getGroupFields(): string
    {
        return 'sm.referencia';
    }

    protected function getSQLFrom(): string
    {
        return 'stocks_movimientos as sm'
            . ' LEFT JOIN variantes as v ON v.referencia = sm.referencia'
            . ' LEFT JOIN productos as p ON p.idproducto = v.idproducto';
    }

    protected function getTables(): array
    {
        return ['productos', 'stocks_movimientos', 'variantes'];
    }
}
