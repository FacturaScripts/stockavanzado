<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2022-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Plugins\StockAvanzado\Extension\Controller;

use Closure;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\DataSrc\Almacenes;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class ReportProducto
{
    protected function createViews(): Closure
    {
        return function () {
            $this->createViewsMovements();
        };
    }

    protected function createViewsMovements(): Closure
    {
        return function ($viewName = 'ListMovimientoProducto') {
            $this->addView($viewName, 'Join\MovimientoProducto', 'movements', 'fa-solid fa-truck-loading')
                ->addOrderBy(['cantidad'], 'quantity')
                ->addSearchFields(['sm.referencia', 'p.descripcion'])
                ->setSettings('btnDelete', false)
                ->setSettings('btnNew', false)
                ->setSettings('checkBoxes', false)
                ->addFilterPeriod('fecha', 'date', 'sm.fecha')
                ->addFilterSelectWhere('type', [
                    ['label' => Tools::trans('all'), 'where' => []],
                    ['label' => '------', 'where' => []],
                    ['label' => Tools::trans('purchases'), 'where' => [new DataBaseWhere('sm.cantidad', 0, '>')]],
                    ['label' => Tools::trans('sales'), 'where' => [new DataBaseWhere('sm.cantidad', 0, '<')]],
                ])
                ->addFilterNumber('cantidadgt', 'quantity', 'cantidad', '>=')
                ->addFilterNumber('cantidadlt', 'quantity', 'cantidad', '<=')
                ->addFilterCheckbox('ex-conteo', 'without-stock-count', 'sm.docmodel', '!=', 'ConteoStock');

            $warehouses = Almacenes::codeModel();
            if (count($warehouses) > 2) {
                $this->addFilterSelect($viewName, 'codalmacen', 'warehouse', 'sm.codalmacen', $warehouses);
            }
        };
    }
}
