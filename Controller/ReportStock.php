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

namespace FacturaScripts\Plugins\StockAvanzado\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\ProductType;

/**
 * Description of ReportStock
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ReportStock extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['title'] = 'stock';
        $data['icon'] = 'fa-solid fa-dolly';
        return $data;
    }

    protected function createViews()
    {
        $this->createViewsStock();
        $this->createViewsMovements();
        $this->createViewsTransfers();
        $this->createViewsCounting();
    }

    protected function createViewsCounting(string $viewName = 'ListConteoStock'): void
    {
        $this->addView($viewName, 'ConteoStock', 'stock-counts', 'fa-solid fa-scroll')
            ->addOrderBy(['fechainicio'], 'date', 2)
            ->addSearchFields(['idconteo', 'observaciones']);

        // Filters
        $this->addFilterPeriod($viewName, 'fechainicio', 'date', 'fechainicio');
        $warehouses = $this->codeModel->all('almacenes', 'codalmacen', 'nombre');
        $this->addFilterSelect($viewName, 'codalmacen', 'warehouse', 'codalmacen', $warehouses);
        $this->addFilterAutocomplete($viewName, 'nick', 'user', 'nick', 'users', 'nick', 'nick');

        // disable buttons
        $this->setSettings($viewName, 'btnDelete', false);
        $this->setSettings($viewName, 'btnNew', false);
        $this->setSettings($viewName, 'checkBoxes', false);
    }

    protected function createViewsMovements(string $viewName = 'ListMovimientoStock'): void
    {
        $warehouses = $this->codeModel->all('almacenes', 'codalmacen', 'nombre');

        $this->addView($viewName, 'MovimientoStock', 'movements', 'fa-solid fa-truck-loading')
            ->addOrderBy(['fecha', 'hora', 'id'], 'date', 2)
            ->addOrderBy(['cantidad'], 'quantity')
            ->addSearchFields(['documento', 'referencia'])
            ->addFilterPeriod('fecha', 'date', 'fecha')
            ->addFilterSelect('codalmacen', 'warehouse', 'codalmacen', $warehouses)
            ->setSettings('btnDelete', false)
            ->setSettings('btnNew', false)
            ->setSettings('checkBoxes', false);
    }

    protected function createViewsStock(string $viewName = 'StockVariante'): void
    {
        $values = [
            [
                'label' => Tools::trans('all'),
                'where' => []
            ],
            [
                'label' => '------',
                'where' => []
            ],
            [
                'label' => Tools::trans('under-minimums'),
                'where' => [new DataBaseWhere('disponible', 'field:stockmin', '<', 'AND', true)]
            ],
            [
                'label' => Tools::trans('excess'),
                'where' => [new DataBaseWhere('disponible', 'field:stockmax', '>', 'AND', true)]
            ]
        ];

        $types = [];
        foreach (ProductType::all() as $key => $key) {
            $types[$key] = Tools::trans($key);
        }

        $warehouses = $this->codeModel->all('almacenes', 'codalmacen', 'nombre');
        $manufacturers = $this->codeModel->all('fabricantes', 'codfabricante', 'nombre');
        $families = $this->codeModel->all('familias', 'codfamilia', 'descripcion');

        $this->addView($viewName, 'Join\StockVariante', 'stock', 'fa-solid fa-dolly')
            ->addOrderBy(['referencia', 'codalmacen'], 'reference')
            ->addOrderBy(['disponible'], 'available')
            ->addOrderBy(['cantidad'], 'quantity', 2)
            ->addOrderBy(['total_movimientos'], 'movements')
            ->addOrderBy(['coste'], 'cost-price')
            ->addOrderBy(['total_precio'], 'total-precio')
            ->addSearchFields(['productos.descripcion', 'stocks.referencia'])
            ->addFilterSelectWhere('type', $values)
            ->addFilterSelectWhere('status', [
                ['label' => Tools::trans('all'), 'where' => []],
                ['label' => Tools::trans('only-active'), 'where' => [new DataBaseWhere('productos.bloqueado', false)]],
                ['label' => Tools::trans('blocked'), 'where' => [new DataBaseWhere('productos.bloqueado', true)]],
                ['label' => Tools::trans('public'), 'where' => [new DataBaseWhere('productos.publico', true)]],
            ])
            ->addFilterSelect('tipo', 'type', 'tipo', $types)
            ->addFilterSelect('codalmacen', 'warehouse', 'stocks.codalmacen', $warehouses)
            ->addFilterSelect('codfabricante', 'manufacturer', 'codfabricante', $manufacturers)
            ->addFilterSelect('codfamilia', 'family', 'codfamilia', $families)
            ->addFilterNumber('max-stock', 'quantity', 'stocks.cantidad', '>=')
            ->addFilterNumber('min-stock', 'quantity', 'stocks.cantidad', '<=')
            ->addFilterCheckbox('secompra', 'for-purchase', 'productos.secompra')
            ->setSettings('btnDelete', false)
            ->setSettings('btnNew', false)
            ->setSettings('checkBoxes', false);
    }

    protected function createViewsTransfers(string $viewName = 'ListTransferenciaStock'): void
    {
        $warehouses = $this->codeModel->all('almacenes', 'codalmacen', 'nombre');

        $this->addView($viewName, 'TransferenciaStock', 'transfers', 'fa-solid fa-exchange-alt')
            ->addOrderBy(['fecha'], 'date', 2)
            ->addSearchFields(['idtrans', 'observaciones'])
            ->addFilterPeriod('fecha', 'date', 'fecha')
            ->addFilterSelect('codalmacenorigen', 'origin-warehouse', 'codalmacenorigen', $warehouses)
            ->addFilterSelect('codalmacendestino', 'destination-warehouse', 'codalmacendestino', $warehouses)
            ->addFilterAutocomplete('nick', 'user', 'nick', 'users', 'nick', 'nick')
            ->setSettings('btnDelete', false)
            ->setSettings('btnNew', false)
            ->setSettings('checkBoxes', false);
    }
}
